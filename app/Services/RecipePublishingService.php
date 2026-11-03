<?php

namespace App\Services;

use App\Enums\RecipeStatus;
use App\Enums\RecipeVisibility;
use App\Models\Recipe;
use App\Models\User;
use App\Notifications\RecipeApproved;
use App\Notifications\RecipeRejected;
use App\Notifications\RecipeSubmittedForReview;
use App\Services\Recipes\RecipeVariationPublishingService;
use App\Support\AppPermissions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class RecipePublishingService
{
    public const MAX_SUBMISSIONS_PER_DAY = 10;

    public const MAX_PENDING_SUBMISSIONS = 5;

    public function __construct(private RecipeMediaStorage $media, private RecipeQuotaService $quota, private AuditLogger $audit, private RecipeRevisionService $revisions) {}

    public function publish(Recipe $recipe): Recipe
    {
        $this->assertPublishable($recipe);

        return DB::transaction(function () use ($recipe): Recipe {
            $recipe->status = RecipeStatus::Published->value;
            $recipe->visibility = RecipeVisibility::Public;
            $recipe->published_at ??= now();
            $recipe->save();

            $revision = $this->revisions->syncPublishedFromLive($recipe->getKey(), Auth::id());
            $recipe->refresh();

            app(DietaryClaimService::class)->bindPendingToRevision($recipe, $revision);
            $this->media->syncStorage($recipe);

            return $recipe;
        });
    }

    /**
     * Approves a pending submission and makes it public. Safe to repeat: the row is locked, and a recipe that is
     * already published is returned unchanged without a second notification.
     */
    public function approve(Recipe $recipe, User $reviewer): Recipe
    {
        $changed = false;

        DB::transaction(function () use ($recipe, $reviewer, &$changed): void {
            $this->lock($recipe);

            if ($this->alreadyApproved($recipe) && $reviewer->can(AppPermissions::RECIPES_REVIEW)) {
                return;
            }

            Gate::forUser($reviewer)->authorize('approve', $recipe);

            $this->assertTransitionAllowed($recipe, RecipeStatus::Published->value);
            $this->assertPublishable($recipe);

            $recipe->forceFill([
                'reviewed_by' => $reviewer->getKey(),
                'reviewed_at' => now(),
                'rejection_reason' => null,
            ]);

            $this->publish($recipe);
            $changed = true;
        });

        if ($changed) {
            $this->audit->record('recipe_approved', ['recipe_id' => $recipe->getKey(), 'author_id' => $recipe->author_id]);
            $recipe->author?->notify(new RecipeApproved($recipe));
        }

        return $recipe;
    }

    /**
     * Sends a pending submission back to its owner with a reason. A rejected recipe stays private. Safe to repeat.
     */
    public function reject(Recipe $recipe, User $reviewer, string $reason): Recipe
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['rejection_reason' => 'A rejection reason is required.']);
        }

        if (mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages(['rejection_reason' => 'Please keep the reason under 1000 characters.']);
        }

        $changed = false;

        DB::transaction(function () use ($recipe, $reviewer, $reason, &$changed): void {
            $this->lock($recipe);

            if ($this->alreadyRejected($recipe) && $reviewer->can(AppPermissions::RECIPES_REVIEW)) {
                return;
            }

            Gate::forUser($reviewer)->authorize('reject', $recipe);

            $recipe->forceFill([
                'reviewed_by' => $reviewer->getKey(),
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ]);

            $this->transition($recipe, RecipeStatus::Draft->value);
            $changed = true;
        });

        if ($changed) {
            $this->audit->record('recipe_rejected', ['recipe_id' => $recipe->getKey(), 'author_id' => $recipe->author_id]);
            $recipe->author?->notify(new RecipeRejected($recipe, $reason));
        }

        return $recipe;
    }

    /**
     * The submission gate: authorised, bilingual-complete, rate limited, then queued for review.
     * Staff who can publish directly are not rate limited.
     */
    public function submit(User $actor, Recipe $recipe): Recipe
    {
        Gate::forUser($actor)->authorize('submitForReview', $recipe);

        $this->assertPublishable($recipe);

        $limited = ! $actor->can(AppPermissions::RECIPES_PUBLISH);
        $key = 'recipe-submission:'.$actor->getKey();

        DB::transaction(function () use ($actor, $recipe, $limited, $key): void {
            // Serialise per submitter so concurrent requests cannot exceed the pending cap.
            User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();

            if ($limited) {
                $pending = Recipe::query()->where('author_id', $actor->getKey())->where('status', RecipeStatus::Pending->value)->count();

                if ($pending >= self::MAX_PENDING_SUBMISSIONS || RateLimiter::tooManyAttempts($key, self::MAX_SUBMISSIONS_PER_DAY)) {
                    throw ValidationException::withMessages(['submission' => __('review.errors.too_many_submissions')]);
                }
            }

            $this->transition($recipe, RecipeStatus::Pending->value);

            if ($limited) {
                RateLimiter::hit($key, 86400);
            }
        });

        $this->audit->record('recipe_submitted', ['recipe_id' => $recipe->getKey()]);

        Notification::send(
            User::permission(AppPermissions::RECIPES_REVIEW)->get()
                ->filter(fn (User $reviewer): bool => $reviewer->hasVerifiedEmail() && $reviewer->isNot($actor)),
            new RecipeSubmittedForReview($recipe),
        );

        return $recipe;
    }

    /**
     * The owner takes a recipe back out of review, or off the public site, to edit it. Public content is never
     * edited in place, so changes always go through review again.
     */
    public function withdraw(User $actor, Recipe $recipe): Recipe
    {
        if ($recipe->derived_from_recipe_id !== null && $recipe->status === 'pending') {
            return app(RecipeVariationPublishingService::class)->withdraw($actor, $recipe);
        }

        Gate::forUser($actor)->authorize('withdraw', $recipe);

        DB::transaction(function () use ($recipe): void {
            $this->lock($recipe);
            $this->transition($recipe, RecipeStatus::Draft->value);
        });

        $this->audit->record('recipe_withdrawn', ['recipe_id' => $recipe->getKey()]);

        return $recipe;
    }

    /**
     * What stops this recipe from being published, as a list of messages. Empty means it is ready.
     *
     * @return list<string>
     */
    public function publicationProblems(Recipe $recipe): array
    {
        try {
            $this->assertPublishable($recipe);
        } catch (ValidationException $exception) {
            return array_values(collect($exception->errors())->flatten()->all());
        }

        return [];
    }

    /**
     * Serialises concurrent decisions on one recipe and reloads its current state.
     */
    private function lock(Recipe $recipe): void
    {
        Recipe::withTrashed()->whereKey($recipe->getKey())->lockForUpdate()->firstOrFail();
        $recipe->refresh();
    }

    private function alreadyApproved(Recipe $recipe): bool
    {
        return $recipe->status === RecipeStatus::Published->value
            && $recipe->visibility === RecipeVisibility::Public
            && $recipe->reviewed_at !== null;
    }

    private function alreadyRejected(Recipe $recipe): bool
    {
        return $recipe->status === RecipeStatus::Draft->value
            && $recipe->reviewed_at !== null
            && filled($recipe->rejection_reason);
    }

    /**
     * Changes who can read the recipe. Going public is a publication and runs the full completeness gate.
     * Leaving public takes effect immediately; share grants are suspended (kept, but inactive) while not shared.
     */
    public function changeVisibility(Recipe $recipe, RecipeVisibility $to): Recipe
    {
        if ($recipe->derived_from_recipe_id !== null && $to !== RecipeVisibility::Private) {
            throw ValidationException::withMessages(['visibility' => __('variations.public_disabled')]);
        }

        if ($recipe->visibility === $to) {
            return $recipe;
        }

        if ($to === RecipeVisibility::Public) {
            if ($recipe->status !== RecipeStatus::Published->value) {
                throw ValidationException::withMessages(['visibility' => 'Only published recipes can be made public.']);
            }

            $this->assertPublishable($recipe);
        }

        $leavesPublic = $recipe->visibility === RecipeVisibility::Public;

        return $this->quota->guardTransition($recipe, $leavesPublic, fn (): Recipe => DB::transaction(function () use ($recipe, $to): Recipe {
            $recipe->visibility = $to;
            $recipe->save();

            if ($to === RecipeVisibility::Public) {
                $this->revisions->syncPublishedFromLive($recipe->getKey(), Auth::id());
                $recipe->refresh();
            }

            $this->media->syncStorage($recipe);

            return $recipe;
        }));
    }

    /**
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        'pending' => ['draft', 'published'],
        'draft' => ['pending', 'published', 'archived'],
        'published' => ['draft', 'pending'],
        'archived' => ['draft', 'published'],
    ];

    public function unpublish(Recipe $recipe): Recipe
    {
        return $this->transition($recipe, 'draft');
    }

    public function archive(Recipe $recipe): Recipe
    {
        return $this->transition($recipe, 'archived');
    }

    public function restoreToDraft(Recipe $recipe): Recipe
    {
        return $this->transition($recipe, 'draft');
    }

    public function publishWithCompletenessChecks(Recipe $recipe): Recipe
    {
        $this->assertTransitionAllowed($recipe, 'published');

        return $this->publish($recipe);
    }

    /**
     * The single gate for publication: complete English and Greek content, ingredients, steps and a cover.
     */
    public function assertPublishable(Recipe $recipe): void
    {
        $this->assertVariationPublicationDisabled($recipe);

        $this->validateTranslatedContent($recipe);
        $this->validateCompleteness($recipe);
    }

    public function validateCompleteness(Recipe $recipe): void
    {
        $errors = [];

        $steps = $recipe->steps()->with('translations')->get();
        $locales = config('translatable.locales');

        $ingredientLines = $recipe->recipeIngredients()->with('ingredient.translations')->get();

        if ($ingredientLines->isEmpty()) {
            $errors['ingredients'] = 'Add at least one ingredient before publishing.';
        } elseif ($ingredientLines->contains(fn ($line): bool => collect($locales)
            ->contains(fn (string $locale): bool => blank($line->ingredient?->getTranslation($locale, false)?->name)))) {
            $errors['ingredients'] = 'Every ingredient needs an English and Greek name before publishing.';
        }

        $stepsAreComplete = $steps->isNotEmpty() && $steps->every(fn ($step): bool => collect(config('translatable.locales'))
            ->every(fn (string $locale): bool => filled($step->getTranslation($locale, false)?->instruction)));

        if (! $stepsAreComplete) {
            $errors['steps'] = 'Add at least one cooking step with English and Greek instructions before publishing.';
        }

        if (collect($locales)->contains(fn (string $locale): bool => blank($recipe->getTranslation($locale, false)?->summary))) {
            $errors['summary'] = 'English and Greek summaries are required before publishing.';
        }

        if (! $recipe->media()->wherePivot('role', 'cover')->exists()) {
            $errors['media'] = 'Upload a cover image before publishing.';
        }

        if ($recipe->categories()->doesntExist()) {
            $errors['categories'] = 'Choose at least one category before publishing.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function validateForPublishing(Recipe $recipe): void
    {
        if ($recipe->derived_from_recipe_id !== null && ! $recipe->currentRevision()->where('recipe_id', $recipe->id)->where('status', 'published')->whereNotNull('publication_context')->exists()) {
            $this->assertVariationPublicationDisabled($recipe);
        }

        $this->validateTranslatedContent($recipe);

        if ($recipe->status === 'published' && $recipe->published_at === null) {
            throw ValidationException::withMessages([
                'published_at' => 'A publication timestamp is required.',
            ]);
        }
    }

    private function assertVariationPublicationDisabled(Recipe $recipe): void
    {
        if ($recipe->derived_from_recipe_id !== null) {
            throw ValidationException::withMessages(['variation' => __('variations.public_disabled')]);
        }
    }

    private function transition(Recipe $recipe, string $status): Recipe
    {
        $this->assertTransitionAllowed($recipe, $status);

        $leavesPublic = $recipe->visibility === RecipeVisibility::Public && $status !== RecipeStatus::Published->value;

        return $this->quota->guardTransition($recipe, $leavesPublic, fn (): Recipe => $this->applyTransition($recipe, $status));
    }

    private function applyTransition(Recipe $recipe, string $status): Recipe
    {
        return DB::transaction(function () use ($recipe, $status): Recipe {
            $recipe->status = $status;

            if ($status === RecipeStatus::Pending->value) {
                $recipe->submitted_at = now();
                $recipe->rejection_reason = null;
            }

            // Anything leaving the published state stops being public in the same write.
            if ($status !== RecipeStatus::Published->value && $recipe->visibility === RecipeVisibility::Public) {
                $recipe->visibility = RecipeVisibility::Private;
            }

            $recipe->save();

            $this->media->syncStorage($recipe);

            return $recipe;
        });
    }

    private function assertTransitionAllowed(Recipe $recipe, string $status): void
    {
        if (! in_array($recipe->status, self::TRANSITIONS[$status] ?? [], true)) {
            throw ValidationException::withMessages([
                'status' => "A {$recipe->status} recipe cannot be moved to {$status}.",
            ]);
        }
    }

    private function validateTranslatedContent(Recipe $recipe): void
    {
        $errors = [];

        foreach (config('translatable.locales') as $locale) {
            $translation = $recipe->getTranslation($locale, false);

            if (blank($translation?->title) || blank($translation?->slug)) {
                $errors['translations'] = 'English and Greek titles and slugs are required before publishing a recipe.';

                break;
            }
        }

        if (! is_numeric($recipe->base_servings) || (float) $recipe->base_servings <= 0) {
            $errors['base_servings'] = 'Base servings must be greater than zero.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
