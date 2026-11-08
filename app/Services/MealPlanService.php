<?php

namespace App\Services;

use App\Enums\HouseholdAbility;
use App\Enums\MealType;
use App\Exceptions\StaleMealPlanException;
use App\Models\BatchCook;
use App\Models\Household;
use App\Models\Ingredient;
use App\Models\IngredientSubstitution;
use App\Models\MealPlan;
use App\Models\MealPlanEntry;
use App\Models\MealPlanEntrySubstitution;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeRevision;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class MealPlanService
{
    public function __construct(
        private RecipeAccessService $recipeAccess,
        private HouseholdAccess $householdAccess,
        private AuditLogger $audit,
        private DietaryClaimVisibility $dietaryClaims,
    ) {}

    /**
     * Reading never creates a plan, so a viewer cannot cause writes.
     */
    public function findPlan(User $actor, ?Household $household, \DateTimeInterface|string $week): ?MealPlan
    {
        $this->authorizeScope($actor, $household, HouseholdAbility::View);

        $plan = $this->scopeQuery($actor, $household)
            ->whereDate('week_start', MealPlan::weekStartFor($week)->toDateString())
            ->first();

        if ($plan) {
            Gate::forUser($actor)->authorize('view', $plan);
        }

        return $plan;
    }

    public function ensurePlan(User $actor, ?Household $household, \DateTimeInterface|string $week): MealPlan
    {
        $this->authorizeScope($actor, $household, HouseholdAbility::EditPlans);

        $weekStart = MealPlan::weekStartFor($week)->toDateString();
        $existing = fn (): ?MealPlan => $this->scopeQuery($actor, $household)->whereDate('week_start', $weekStart)->first();

        try {
            return $existing() ?? MealPlan::create([
                'household_id' => $household?->getKey(),
                'created_by' => $actor->getKey(),
                'week_start' => $weekStart,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent request created the same week first; use theirs.
            return $existing() ?? throw new StaleMealPlanException;
        }
    }

    /**
     * @param  array<int|string, array{substitution_id: int|string, replacement_ingredient_id: int|string}>  $confirmedSubstitutions
     */
    public function addEntry(
        User $actor,
        MealPlan $plan,
        Recipe $recipe,
        \DateTimeInterface|string $date,
        MealType $meal,
        int $servings,
        ?string $notes = null,
        array $confirmedSubstitutions = [],
    ): MealPlanEntry {
        Gate::forUser($actor)->authorize('update', $plan);

        $day = CarbonImmutable::parse($date)->startOfDay();
        $this->assertWithinWeek($plan, $day);
        $this->assertServings($servings);
        $this->assertRecipeAddable($actor, $plan, $recipe);

        return DB::transaction(function () use ($actor, $plan, $recipe, $day, $meal, $servings, $notes, $confirmedSubstitutions): MealPlanEntry {
            // Serialises additions so the entry limit cannot be exceeded by concurrent requests.
            MealPlan::query()->whereKey($plan->getKey())->lockForUpdate()->firstOrFail();

            if ($plan->entries()->count() >= config('planner.max_entries_per_plan')) {
                throw ValidationException::withMessages(['recipe' => __('planner.errors.entry_limit')]);
            }

            $confirmedSubstitutions = $this->validatedSubstitutionChoices($recipe, $confirmedSubstitutions, $actor);
            $entry = $plan->entries()->create([
                'recipe_id' => $recipe->getKey(),
                'planned_recipe_revision_id' => $recipe->isPubliclyAccessible() && $recipe->current_revision_id !== null
                    ? $recipe->current_revision_id : app(RecipeRevisionService::class)->captureComponentSnapshot($actor, $recipe)->id,
                'planned_on' => $day->toDateString(),
                'meal_type' => $meal,
                'servings' => $servings,
                'notes' => filled($notes) ? trim($notes) : null,
                'added_by' => $actor->getKey(),
            ]);

            if ($confirmedSubstitutions !== []) {
                $lines = RecipeIngredient::query()->whereIn('id', array_keys($confirmedSubstitutions))->get();

                foreach ($lines as $line) {
                    $entry->confirmedSubstitutions()->create([
                        'recipe_id' => $line->recipe_id,
                        'recipe_ingredient_id' => $line->getKey(),
                        'ingredient_substitution_id' => $confirmedSubstitutions[$line->id]['substitution_id'],
                        'replacement_ingredient_id' => $confirmedSubstitutions[$line->id]['replacement_ingredient_id'],
                        'confirmed_by' => $actor->getKey(),
                        'confirmed_at' => now(),
                    ]);
                }
            }

            $this->audit->record('meal_plan_entry_added', ['meal_plan_id' => $plan->getKey(), 'entry_id' => $entry->getKey(), 'recipe_id' => $recipe->getKey()]);

            return $entry;
        });
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $choices
     * @return array<int, array{substitution_id: int, replacement_ingredient_id: int}>
     */
    public function validatedSubstitutionChoices(Recipe $recipe, array $choices, ?User $viewer = null): array
    {
        if (count($choices) > 100) {
            throw ValidationException::withMessages(['substitutions' => __('site.substitution_invalid_confirmation')]);
        }

        $normalized = [];

        foreach ($choices as $ingredientId => $choice) {
            if (
                (! is_int($ingredientId) && (! is_string($ingredientId) || ! ctype_digit($ingredientId)))
                || ! is_array($choice)
                || count($choice) !== 2
                || ! array_key_exists('substitution_id', $choice)
                || ! array_key_exists('replacement_ingredient_id', $choice)
                || (! is_int($choice['substitution_id']) && (! is_string($choice['substitution_id']) || ! ctype_digit($choice['substitution_id'])))
                || (! is_int($choice['replacement_ingredient_id']) && (! is_string($choice['replacement_ingredient_id']) || ! ctype_digit($choice['replacement_ingredient_id'])))
                || (int) $ingredientId < 1
                || (int) $choice['substitution_id'] < 1
                || (int) $choice['replacement_ingredient_id'] < 1
            ) {
                throw ValidationException::withMessages(['substitutions' => __('site.substitution_invalid_confirmation')]);
            }

            $normalized[(int) $ingredientId] = [
                'substitution_id' => (int) $choice['substitution_id'],
                'replacement_ingredient_id' => (int) $choice['replacement_ingredient_id'],
            ];
        }

        if ($normalized === []) {
            return [];
        }

        $resolved = app(RecipeComponentResolver::class)->resolve($recipe, $viewer ?? auth()->user(), purpose: 'discovery');
        if ($resolved['unresolved'] !== []) {
            throw ValidationException::withMessages(['substitutions' => __('site.substitution_invalid_confirmation')]);
        }
        $lines = collect($resolved['lines']);
        $substitutions = IngredientSubstitution::query()->approved()->whereIn('id', array_column($normalized, 'substitution_id'))->get()->keyBy('id');
        $rows = [];
        foreach ($normalized as $key => $choice) {
            $substitution = $substitutions->get($choice['substitution_id']);
            // Accept legacy ingredient keys, but store confirmations against their actual row identities.
            $matching = $lines->filter(fn ($line) => ($line->source['recipe_ingredient_id'] ?? null) == $key && $substitution !== null && $line->ingredientId == $substitution->ingredient_id);
            if ($matching->isEmpty()) {
                $matching = $lines->filter(fn ($line) => $line->ingredientId === $key);
            }
            if ($matching->isEmpty() || $substitution === null || (int) $substitution->replacement_ingredient_id !== $choice['replacement_ingredient_id']) {
                throw ValidationException::withMessages(['substitutions' => __('site.substitution_invalid_confirmation')]);
            }
            foreach ($matching as $line) {
                $rowId = $line->source['recipe_ingredient_id'] ?? null;
                if (! $rowId || (int) $substitution->ingredient_id !== $line->ingredientId || ! $substitution->appliesToResolvedSource((array) $line->source)) {
                    throw ValidationException::withMessages(['substitutions' => __('site.substitution_invalid_confirmation')]);
                }
                $rows[(int) $rowId] = $choice;
            }
        }
        $normalized = $rows;

        return $normalized;
    }

    /**
     * @param  array{planned_on?: string, meal_type?: MealType, servings?: int, notes?: ?string}  $changes
     */
    public function updateEntry(User $actor, MealPlanEntry $entry, int $expectedVersion, array $changes): MealPlanEntry
    {
        $plan = $entry->mealPlan;
        Gate::forUser($actor)->authorize('update', $plan);

        // Someone who can no longer see the recipe must not edit (or learn about) it; they may only remove it.
        abort_unless($this->entryRecipeVisibleTo($actor, $entry), 404);

        $this->assertEntryEditable($entry, $changes);

        $values = [];

        if (isset($changes['planned_on'])) {
            $day = CarbonImmutable::parse($changes['planned_on'])->startOfDay();
            $this->assertWithinWeek($plan, $day);
            $this->assertNotBeforeCooked($entry, $day);
            $values['planned_on'] = $day->toDateString();
        }

        if (isset($changes['meal_type'])) {
            $values['meal_type'] = $changes['meal_type']->value;
        }

        if (isset($changes['servings'])) {
            $this->assertServings($changes['servings']);
            $values['servings'] = $changes['servings'];
        }

        if (array_key_exists('notes', $changes)) {
            $values['notes'] = filled($changes['notes']) ? trim($changes['notes']) : null;
        }

        // Compare-and-swap: only the holder of the current version wins; everyone else gets a conflict.
        $updated = MealPlanEntry::query()
            ->whereKey($entry->getKey())
            ->where('version', $expectedVersion)
            ->update([...$values, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);

        if ($updated === 0) {
            throw new StaleMealPlanException;
        }

        $this->audit->record('meal_plan_entry_updated', ['meal_plan_id' => $plan->getKey(), 'entry_id' => $entry->getKey()]);

        return $entry->refresh();
    }

    /**
     * The planner keeps showing the newest approved recipe, but the user decides when to accept a change. Accepting
     * only re-pins the entry; shopping lists are regenerated separately and explicitly.
     */
    public function acknowledgeRecipeUpdate(User $actor, MealPlanEntry $entry, int $expectedVersion): MealPlanEntry
    {
        Gate::forUser($actor)->authorize('update', $entry->mealPlan);
        abort_unless($this->entryRecipeVisibleTo($actor, $entry), 404);

        $updated = MealPlanEntry::query()
            ->whereKey($entry->getKey())
            ->where('version', $expectedVersion)
            ->update([
                'planned_recipe_revision_id' => $entry->recipe->isPubliclyAccessible() && $entry->recipe->current_revision_id !== null
                    ? $entry->recipe->current_revision_id : app(RecipeRevisionService::class)->captureComponentSnapshot($actor, $entry->recipe)->id,
                'version' => DB::raw('version + 1'),
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            throw new StaleMealPlanException;
        }

        $this->audit->record('meal_plan_entry_recipe_update_acknowledged', ['meal_plan_id' => $entry->meal_plan_id, 'entry_id' => $entry->getKey()]);

        return $entry->refresh();
    }

    /**
     * Removing something already removed succeeds quietly; removing a changed entry is a conflict.
     */
    public function removeEntry(User $actor, MealPlanEntry $entry, int $expectedVersion): void
    {
        Gate::forUser($actor)->authorize('update', $entry->mealPlan);

        // Leftover meals hold a reservation on a batch; the batch service returns it under the batch lock.
        if ($entry->batch_cook_id !== null) {
            app(BatchCookService::class)->removeLeftover($actor, $entry, $expectedVersion);

            return;
        }

        $current = MealPlanEntry::query()->whereKey($entry->getKey())->value('version');

        if ($current === null) {
            return;
        }

        $deleted = MealPlanEntry::query()->whereKey($entry->getKey())->where('version', $expectedVersion)->delete();

        if ($deleted === 0) {
            throw new StaleMealPlanException;
        }

        $this->audit->record('meal_plan_entry_removed', ['meal_plan_id' => $entry->meal_plan_id, 'entry_id' => $entry->getKey()]);
    }

    /**
     * Entries grouped by date then meal for one viewer. Entries whose recipe this viewer cannot see carry no recipe data.
     *
     * @return Collection<string, Collection<string, Collection<int, array<string, mixed>>>>
     */
    public function weekView(User $viewer, MealPlan $plan, string $locale): Collection
    {
        Gate::forUser($viewer)->authorize('view', $plan);

        $entries = $plan->entries()
            ->with([
                'recipe.translations',
                'recipe.media.translations',
                'recipe.recipeIngredients.ingredient.translations',
                'recipe.recipeIngredients.ingredient.catalogGroup.translations',
                'confirmedSubstitutions.ingredientSubstitution.translations',
                'confirmedSubstitutions.ingredientSubstitution.replacementIngredient.translations',
                'confirmedSubstitutions.recipeIngredient.ingredient.translations',
                'confirmedSubstitutions.recipeIngredient.ingredient.catalogGroup.translations',
                'confirmedSubstitutions.replacementIngredient.translations',
                'confirmedSubstitutions.replacementIngredient.catalogGroup.translations',
            ])
            ->orderBy('planned_on')
            ->orderBy('id')
            ->get();

        $batchSources = BatchCook::query()->whereIn('source_entry_id', $entries->pluck('id'))->pluck('id', 'source_entry_id');

        $pinned = RecipeRevision::whereIn('id', $entries->pluck('planned_recipe_revision_id')->filter())->with('recipe')->get()->keyBy('id');
        $audience = $plan->isPersonal() ? collect([User::find($plan->created_by)]) : $plan->household->members()->with('user')->get()->pluck('user');
        $resolvedEntries = [];
        foreach ($entries as $entry) {
            $subject = $entry->planned_recipe_revision_id !== null ? $pinned->get($entry->planned_recipe_revision_id) : $entry->recipe;
            if ($subject !== null && $audience->isNotEmpty() && $audience->every(fn ($user) => $user && app(RecipeComponentResolver::class)->canResolve($subject, $user)) && app(RecipeComponentResolver::class)->canResolve($subject, $viewer)) {
                $resolvedEntries[$entry->id] = app(RecipeComponentResolver::class)->resolve($subject, $viewer, (string) $entry->servings, purpose: 'display');
            }
        }
        $ingredientIds = collect($resolvedEntries)->flatMap(fn ($result) => collect($result['lines'])->map(fn ($line) => $line->ingredientId))->unique();
        $ingredients = Ingredient::whereIn('id', $ingredientIds)->with(['translations', 'catalogGroup.translations'])->get()->keyBy('id');

        $items = $entries->map(function (MealPlanEntry $entry) use ($locale, $batchSources, $pinned, $resolvedEntries, $ingredients): array {
            $recipe = $entry->recipe;
            $subject = $entry->planned_recipe_revision_id !== null ? $pinned->get($entry->planned_recipe_revision_id) : $recipe;
            $available = isset($resolvedEntries[$entry->id]);
            $resolved = $resolvedEntries[$entry->id] ?? ['lines' => [], 'components' => []];
            $translation = $available ? $recipe->displayTranslation($locale) : null;
            $confirmedSubstitutions = $available
                ? $entry->confirmedSubstitutions
                    ->filter(fn (MealPlanEntrySubstitution $confirmation): bool => $confirmation->recipeIngredient?->ingredient !== null && $confirmation->replacementIngredient !== null && $confirmation->ingredientSubstitution?->status === 'approved'
                        && collect($resolved['lines'])->contains(fn ($line): bool => (int) ($line->source['recipe_ingredient_id'] ?? 0) === (int) $confirmation->recipe_ingredient_id
                            && $confirmation->ingredientSubstitution->appliesToResolvedSource((array) $line->source))
                        && (int) $confirmation->ingredientSubstitution->ingredient_id
                            === (int) $confirmation->recipeIngredient?->ingredient_id
                        && (int) $confirmation->ingredientSubstitution->replacement_ingredient_id
                            === (int) $confirmation->replacement_ingredient_id)
                    ->map(fn (MealPlanEntrySubstitution $confirmation): array => [
                        'original' => (string) ($confirmation->recipeIngredient->ingredient->translate($locale)?->name
                            ?? $confirmation->recipeIngredient->ingredient->code),
                        'replacement' => (string) ($confirmation->replacementIngredient->translate($locale)?->name
                            ?? $confirmation->replacementIngredient->code),
                        'explanation' => $confirmation->ingredientSubstitution->translate($locale)?->explanation,
                        'original_url' => $confirmation->recipeIngredient->ingredient->encyclopediaUrl($locale),
                        'replacement_url' => $confirmation->replacementIngredient->encyclopediaUrl($locale),
                    ])
                    ->values()
                    ->all()
                : [];

            return [
                'id' => $entry->getKey(),
                'version' => $entry->version,
                'date' => $entry->planned_on->toDateString(),
                'meal' => $entry->meal_type,
                'servings' => $entry->servings,
                'available' => $available,
                'recipe_updated' => $available && $entry->planned_recipe_revision_id !== null && $recipe->current_revision_id !== null && $entry->planned_recipe_revision_id !== $recipe->current_revision_id,
                'title' => $available && $subject instanceof RecipeRevision ? ($subject->payload['translations'][$locale]['title'] ?? $translation?->title) : $translation?->title,
                'url' => $translation ? route('recipes.show', ['locale' => $translation->locale, 'slug' => $translation->slug], false) : null,
                'notes' => $available ? $entry->notes : null,
                'substitutions' => $confirmedSubstitutions,
                'components' => $available ? $resolved['components'] : [],
                'ingredients' => $available ? collect($resolved['lines'])->unique('ingredientId')->map(fn ($line) => $ingredients->get($line->ingredientId))->filter()->map(fn ($ingredient): array => [
                    'name' => (string) ($ingredient->translate($locale)?->name ?? $ingredient->code),
                    'url' => $ingredient->encyclopediaUrl($locale),
                ])->values()->all() : [],
                'leftover' => $entry->batch_cook_id !== null,
                'consumed' => $entry->consumed_at !== null,
                'has_batch' => $entry->batch_cook_id === null && isset($batchSources[$entry->getKey()]),
            ];
        });

        return $items->groupBy('date')->map(fn (Collection $day): Collection => $day->groupBy(fn (array $item): string => $item['meal']->value));
    }

    /**
     * Strict rule: a recipe may join a shared plan only if every current household member may view it.
     */
    public function recipeAddable(User $actor, ?Household $household, Recipe $recipe): bool
    {
        if (! app(RecipeComponentResolver::class)->canResolve($recipe, $actor)) {
            return false;
        }

        if ($household === null) {
            return true;
        }

        return $household->members()->with('user')->get()
            ->every(fn ($member): bool => app(RecipeComponentResolver::class)->canResolve($recipe, $member->user));
    }

    /**
     * Recipes the actor may add, matched by title in either language.
     *
     * @return Collection<int, Recipe>
     */
    public function searchRecipes(
        User $actor,
        ?Household $household,
        string $term,
        string $locale,
        int $limit = 8,
        string $dietaryClaim = '',
    ): Collection {
        $this->authorizeScope($actor, $household, HouseholdAbility::EditPlans);

        $term = trim($term);

        if (mb_strlen($term) < 2) {
            return collect();
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        $query = Recipe::query()
            ->whereHas('translations', fn ($q) => $q->where('title', 'ilike', $like))
            ->where(fn ($q) => $q
                ->where(fn ($published) => $published->published())
                ->orWhere('author_id', $actor->getKey())
                ->orWhereHas('shares', fn ($shares) => $shares->active()->where('user_id', $actor->getKey())))
            ->with(['translations']);

        if ($dietaryClaim !== '') {
            $claimIndex = $this->dietaryClaims->publicClaimIndex();
            $query->whereIn('recipes.id', $claimIndex[$dietaryClaim] ?? []);
        }

        return $query
            ->limit($limit * 4)
            ->get()
            ->filter(fn (Recipe $recipe): bool => $this->recipeAddable($actor, $household, $recipe))
            ->take($limit)
            ->values();
    }

    private function entryRecipeVisibleTo(User $actor, MealPlanEntry $entry): bool
    {
        return $entry->recipe !== null && app(RecipeComponentResolver::class)->canResolve($entry->planned_recipe_revision_id !== null ? RecipeRevision::find($entry->planned_recipe_revision_id) ?? $entry->recipe : $entry->recipe, $actor);
    }

    private function assertRecipeAddable(User $actor, MealPlan $plan, Recipe $recipe): void
    {
        if ($this->recipeAddable($actor, $plan->household_id ? $plan->household : null, $recipe)) {
            return;
        }

        // Same message whether the recipe is missing for the actor or hidden from another member.
        throw ValidationException::withMessages(['recipe' => __('planner.errors.recipe_not_shareable')]);
    }

    /**
     * Servings are fixed once a batch depends on an entry, and an eaten leftover is history.
     *
     * @param  array<string, mixed>  $changes
     */
    private function assertEntryEditable(MealPlanEntry $entry, array $changes): void
    {
        if ($entry->consumed_at !== null) {
            throw ValidationException::withMessages(['entry' => __('planner.errors.entry_locked')]);
        }

        $lockedServings = $entry->batch_cook_id !== null || BatchCook::query()->where('source_entry_id', $entry->getKey())->exists();

        if ($lockedServings && isset($changes['servings']) && (int) $changes['servings'] !== $entry->servings) {
            throw ValidationException::withMessages(['entry' => __('planner.errors.entry_locked')]);
        }
    }

    private function assertNotBeforeCooked(MealPlanEntry $entry, CarbonImmutable $day): void
    {
        if ($entry->batch_cook_id === null) {
            return;
        }

        $cooked = BatchCook::query()->whereKey($entry->batch_cook_id)->value('cooked_on');

        if ($cooked !== null && $day->lt(CarbonImmutable::parse($cooked)->startOfDay())) {
            throw ValidationException::withMessages(['date' => __('planner.batches.errors.before_cooked')]);
        }
    }

    private function assertWithinWeek(MealPlan $plan, CarbonImmutable $day): void
    {
        $start = CarbonImmutable::parse($plan->week_start)->startOfDay();

        if ($day->lt($start) || $day->gt($start->addDays(6))) {
            throw ValidationException::withMessages(['date' => __('planner.errors.date_outside_week')]);
        }
    }

    private function assertServings(int $servings): void
    {
        if ($servings < 1 || $servings > 99) {
            throw ValidationException::withMessages(['servings' => __('planner.errors.servings')]);
        }
    }

    private function authorizeScope(User $actor, ?Household $household, HouseholdAbility $ability): void
    {
        $allowed = $household === null
            ? $this->householdAccess->canUsePlanner($actor)
            : $this->householdAccess->can($actor, $household, $ability);

        abort_unless($allowed, 403);
    }

    private function scopeQuery(User $actor, ?Household $household)
    {
        return $household === null
            ? MealPlan::query()->whereNull('household_id')->where('created_by', $actor->getKey())
            : MealPlan::query()->where('household_id', $household->getKey());
    }
}
