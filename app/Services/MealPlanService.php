<?php

namespace App\Services;

use App\Enums\HouseholdAbility;
use App\Enums\MealType;
use App\Exceptions\StaleMealPlanException;
use App\Models\BatchCook;
use App\Models\Household;
use App\Models\MealPlan;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
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

    public function addEntry(User $actor, MealPlan $plan, Recipe $recipe, \DateTimeInterface|string $date, MealType $meal, int $servings, ?string $notes = null): MealPlanEntry
    {
        Gate::forUser($actor)->authorize('update', $plan);

        $day = CarbonImmutable::parse($date)->startOfDay();
        $this->assertWithinWeek($plan, $day);
        $this->assertServings($servings);
        $this->assertRecipeAddable($actor, $plan, $recipe);

        return DB::transaction(function () use ($actor, $plan, $recipe, $day, $meal, $servings, $notes): MealPlanEntry {
            // Serialises additions so the entry limit cannot be exceeded by concurrent requests.
            MealPlan::query()->whereKey($plan->getKey())->lockForUpdate()->firstOrFail();

            if ($plan->entries()->count() >= config('planner.max_entries_per_plan')) {
                throw ValidationException::withMessages(['recipe' => __('planner.errors.entry_limit')]);
            }

            $entry = $plan->entries()->create([
                'recipe_id' => $recipe->getKey(),
                'planned_on' => $day->toDateString(),
                'meal_type' => $meal,
                'servings' => $servings,
                'notes' => filled($notes) ? trim($notes) : null,
                'added_by' => $actor->getKey(),
            ]);

            $this->audit->record('meal_plan_entry_added', ['meal_plan_id' => $plan->getKey(), 'entry_id' => $entry->getKey(), 'recipe_id' => $recipe->getKey()]);

            return $entry;
        });
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
            ->with(['recipe.translations', 'recipe.media.translations'])
            ->orderBy('planned_on')
            ->orderBy('id')
            ->get();

        $batchSources = BatchCook::query()->whereIn('source_entry_id', $entries->pluck('id'))->pluck('id', 'source_entry_id');

        $items = $entries->map(function (MealPlanEntry $entry) use ($viewer, $locale, $batchSources): array {
            $recipe = $entry->recipe;
            $available = $recipe !== null && $this->recipeAccess->canView($viewer, $recipe);
            $translation = $available ? $recipe->displayTranslation($locale) : null;

            return [
                'id' => $entry->getKey(),
                'version' => $entry->version,
                'date' => $entry->planned_on->toDateString(),
                'meal' => $entry->meal_type,
                'servings' => $entry->servings,
                'available' => $available,
                'title' => $translation?->title,
                'url' => $translation ? route('recipes.show', ['locale' => $translation->locale, 'slug' => $translation->slug], false) : null,
                'notes' => $available ? $entry->notes : null,
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
        if (! $this->recipeAccess->canView($actor, $recipe)) {
            return false;
        }

        if ($household === null || $recipe->isPubliclyAccessible()) {
            return true;
        }

        return $household->members()->with('user')->get()
            ->every(fn ($member): bool => $this->recipeAccess->canView($member->user, $recipe));
    }

    /**
     * Recipes the actor may add, matched by title in either language.
     *
     * @return Collection<int, Recipe>
     */
    public function searchRecipes(User $actor, ?Household $household, string $term, string $locale, int $limit = 8): Collection
    {
        $this->authorizeScope($actor, $household, HouseholdAbility::EditPlans);

        $term = trim($term);

        if (mb_strlen($term) < 2) {
            return collect();
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        return Recipe::query()
            ->whereHas('translations', fn ($q) => $q->where('title', 'ilike', $like))
            ->where(fn ($q) => $q
                ->where(fn ($published) => $published->published())
                ->orWhere('author_id', $actor->getKey())
                ->orWhereHas('shares', fn ($shares) => $shares->active()->where('user_id', $actor->getKey())))
            ->with(['translations'])
            ->limit($limit * 4)
            ->get()
            ->filter(fn (Recipe $recipe): bool => $this->recipeAddable($actor, $household, $recipe))
            ->take($limit)
            ->values();
    }

    private function entryRecipeVisibleTo(User $actor, MealPlanEntry $entry): bool
    {
        return $entry->recipe !== null && $this->recipeAccess->canView($actor, $entry->recipe);
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
