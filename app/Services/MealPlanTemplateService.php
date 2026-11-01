<?php

namespace App\Services;

use App\Enums\HouseholdAbility;
use App\Exceptions\StaleTemplateException;
use App\Models\Household;
use App\Models\MealPlan;
use App\Models\MealPlanTemplate;
use App\Models\MealPlanTemplateEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Reusable weekly patterns. A template stores recipe, weekday, meal and servings, so it fits any week.
 *
 * Templates never bypass recipe access: saving keeps only recipes that can be shared with the template's audience,
 * and applying re-checks every recipe against the target plan's audience, because access may have changed since.
 * Applying merges into the week and skips slots that already exist, so repeating it changes nothing.
 */
class MealPlanTemplateService
{
    public function __construct(
        private MealPlanService $plans,
        private RecipeAccessService $recipeAccess,
        private HouseholdAccess $householdAccess,
        private AuditLogger $audit,
    ) {}

    /**
     * @return Collection<int, MealPlanTemplate>
     */
    public function list(User $actor, ?Household $household): Collection
    {
        $this->authorizeScope($actor, $household, HouseholdAbility::View);

        return $this->scopeQuery($actor, $household)->withCount('entries')->orderBy('name')->orderBy('id')->get();
    }

    /**
     * @return array{template: MealPlanTemplate, skipped: int}
     */
    public function saveFromPlan(User $actor, MealPlan $plan, string $name): array
    {
        Gate::forUser($actor)->authorize('view', $plan);

        $household = $plan->isPersonal() ? null : $plan->household;
        $this->authorizeScope($actor, $household, HouseholdAbility::EditPlans);

        $name = $this->validName($name);
        $entries = $plan->entries()->with('recipe')->orderBy('planned_on')->orderBy('id')->get();
        $keep = $entries->filter(fn ($entry): bool => $entry->recipe !== null && $this->plans->recipeAddable($actor, $household, $entry->recipe));

        if ($keep->isEmpty()) {
            throw ValidationException::withMessages(['templateName' => __('planner.templates.errors.nothing_to_save')]);
        }

        $template = DB::transaction(function () use ($actor, $household, $name, $keep): MealPlanTemplate {
            // Serialises creation so the per-scope limit holds under concurrent requests.
            $this->lockScope($actor, $household);

            if ($this->scopeQuery($actor, $household)->count() >= config('planner.max_templates_per_scope')) {
                throw ValidationException::withMessages(['templateName' => __('planner.templates.errors.limit')]);
            }

            $template = MealPlanTemplate::create(['household_id' => $household?->getKey(), 'created_by' => $actor->getKey(), 'name' => $name]);

            $rows = $keep->map(fn ($entry): array => [
                'meal_plan_template_id' => $template->getKey(),
                'recipe_id' => $entry->recipe_id,
                'day_of_week' => $entry->planned_on->dayOfWeekIso,
                'meal_type' => $entry->meal_type->value,
                'servings' => $entry->servings,
                'notes' => $entry->notes,
                'created_at' => now(),
                'updated_at' => now(),
            ])->unique(fn (array $row): string => $row['recipe_id'].'-'.$row['day_of_week'].'-'.$row['meal_type'])->values()->all();

            MealPlanTemplateEntry::query()->insert($rows);
            $this->audit->record('meal_plan_template_saved', ['template_id' => $template->getKey(), 'entries' => count($rows)]);

            return $template;
        });

        return ['template' => $template, 'skipped' => $entries->count() - $keep->count()];
    }

    /**
     * @return array{added: int, duplicates: int, unavailable: int, over_limit: int}
     */
    public function apply(User $actor, MealPlanTemplate $template, MealPlan $plan): array
    {
        Gate::forUser($actor)->authorize('view', $template);
        Gate::forUser($actor)->authorize('update', $plan);

        $household = $plan->isPersonal() ? null : $plan->household;
        $start = CarbonImmutable::parse($plan->week_start)->startOfDay();
        $counts = ['added' => 0, 'duplicates' => 0, 'unavailable' => 0, 'over_limit' => 0];

        DB::transaction(function () use ($actor, $template, $plan, $household, $start, &$counts): void {
            MealPlan::query()->whereKey($plan->getKey())->lockForUpdate()->firstOrFail();

            $existing = $plan->entries()->get(['recipe_id', 'planned_on', 'meal_type'])
                ->map(fn ($entry): string => $this->slot($entry->recipe_id, $entry->planned_on->toDateString(), $entry->meal_type->value))
                ->flip();
            $total = $existing->count();

            foreach ($template->entries()->with('recipe')->orderBy('day_of_week')->orderBy('id')->get() as $item) {
                $date = $start->addDays($item->day_of_week - 1)->toDateString();

                if ($item->recipe === null || ! $this->plans->recipeAddable($actor, $household, $item->recipe)) {
                    $counts['unavailable']++;

                    continue;
                }

                if ($existing->has($this->slot($item->recipe_id, $date, $item->meal_type->value))) {
                    $counts['duplicates']++;

                    continue;
                }

                if ($total >= config('planner.max_entries_per_plan')) {
                    $counts['over_limit']++;

                    continue;
                }

                $plan->entries()->create([
                    'recipe_id' => $item->recipe_id,
                    'planned_on' => $date,
                    'meal_type' => $item->meal_type,
                    'servings' => $item->servings,
                    'notes' => $item->notes,
                    'added_by' => $actor->getKey(),
                ]);
                $existing->put($this->slot($item->recipe_id, $date, $item->meal_type->value), true);
                $total++;
                $counts['added']++;
            }

            $this->audit->record('meal_plan_template_applied', ['template_id' => $template->getKey(), 'meal_plan_id' => $plan->getKey(), 'added' => $counts['added']]);
        });

        return $counts;
    }

    public function rename(User $actor, MealPlanTemplate $template, int $expectedVersion, string $name): MealPlanTemplate
    {
        Gate::forUser($actor)->authorize('update', $template);

        $name = $this->validName($name);

        $updated = MealPlanTemplate::query()
            ->whereKey($template->getKey())
            ->where('version', $expectedVersion)
            ->update(['name' => $name, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);

        if ($updated === 0) {
            throw new StaleTemplateException;
        }

        return $template->refresh();
    }

    /**
     * Deleting something already gone succeeds quietly; deleting a changed template is a conflict.
     */
    public function delete(User $actor, MealPlanTemplate $template, int $expectedVersion): void
    {
        Gate::forUser($actor)->authorize('delete', $template);

        $current = MealPlanTemplate::query()->whereKey($template->getKey())->value('version');

        if ($current === null) {
            return;
        }

        if (MealPlanTemplate::query()->whereKey($template->getKey())->where('version', $expectedVersion)->delete() === 0) {
            throw new StaleTemplateException;
        }

        $this->audit->record('meal_plan_template_deleted', ['template_id' => $template->getKey()]);
    }

    /**
     * Template contents for one viewer. Recipes the viewer cannot see carry no title or link.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function entriesView(User $viewer, MealPlanTemplate $template, string $locale): Collection
    {
        Gate::forUser($viewer)->authorize('view', $template);

        return $template->entries()->with('recipe.translations')->orderBy('day_of_week')->orderBy('id')->get()
            ->map(function (MealPlanTemplateEntry $entry) use ($viewer, $locale): array {
                $available = $entry->recipe !== null && $this->recipeAccess->canView($viewer, $entry->recipe);

                return [
                    'id' => $entry->getKey(),
                    'day' => $entry->day_of_week,
                    'meal' => $entry->meal_type,
                    'servings' => $entry->servings,
                    'available' => $available,
                    'title' => $available ? $entry->recipe->displayTranslation($locale)?->title : null,
                ];
            });
    }

    private function slot(int $recipeId, string $date, string $meal): string
    {
        return $recipeId.'|'.$date.'|'.$meal;
    }

    private function validName(string $name): string
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 100) {
            throw ValidationException::withMessages(['templateName' => __('planner.templates.errors.name')]);
        }

        return $name;
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
            ? MealPlanTemplate::query()->whereNull('household_id')->where('created_by', $actor->getKey())
            : MealPlanTemplate::query()->where('household_id', $household->getKey());
    }

    private function lockScope(User $actor, ?Household $household): void
    {
        $household === null
            ? User::query()->whereKey($actor->getKey())->lockForUpdate()->first()
            : Household::query()->whereKey($household->getKey())->lockForUpdate()->first();
    }
}
