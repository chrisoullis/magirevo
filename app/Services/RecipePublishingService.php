<?php

namespace App\Services;

use App\Models\Recipe;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecipePublishingService
{
    public function publish(Recipe $recipe): Recipe
    {
        $this->assertPublishable($recipe);

        return DB::transaction(function () use ($recipe): Recipe {
            $recipe->status = 'published';
            $recipe->published_at ??= now();
            $recipe->save();

            return $recipe;
        });
    }

    /**
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        'pending' => ['draft'],
        'draft' => ['pending', 'published', 'archived'],
        'published' => ['draft', 'pending'],
        'archived' => ['draft', 'published'],
    ];

    public function submitForReview(Recipe $recipe): Recipe
    {
        return $this->transition($recipe, 'pending');
    }

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
        $this->validateTranslatedContent($recipe);

        if ($recipe->status === 'published' && $recipe->published_at === null) {
            throw ValidationException::withMessages([
                'published_at' => 'A publication timestamp is required.',
            ]);
        }
    }

    private function transition(Recipe $recipe, string $status): Recipe
    {
        $this->assertTransitionAllowed($recipe, $status);

        $recipe->status = $status;
        $recipe->save();

        return $recipe;
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
