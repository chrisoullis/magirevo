<?php

namespace App\Services;

use App\Models\MealPlan;
use App\Models\MealPlanEntry;
use App\Models\MealPlanEntrySubstitution;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\User;
use App\Support\QuantityLine;
use Illuminate\Support\Collection;

/**
 * Turns a meal plan into scaled ingredient lines using a fixed number of queries, however many meals it holds.
 *
 * Strict access: a recipe contributes only while every person the plan serves can still view it (the owner for a
 * personal plan, every current member for a household plan). Anything else is left out and reported by entry id only,
 * so nothing about a private recipe is exposed.
 */
class PlanIngredientCollector
{
    public function __construct(private RecipeAccessService $access, private ServingAdjustmentService $scaling) {}

    /**
     * @return array{lines: list<QuantityLine>, excluded_entry_ids: list<int>}
     */
    public function collect(MealPlan $plan): array
    {
        // Leftover meals are eaten from a batch whose cooking meal already counts every portion, so they add no ingredients.
        $entries = $plan->entries()->whereNull('batch_cook_id')->with('recipe')->orderBy('id')->get();
        $audience = $this->audience($plan);

        $includable = $entries->pluck('recipe')->filter()->unique('id')
            ->filter(fn (Recipe $recipe): bool => $audience->isNotEmpty() && $audience->every(fn (User $user): bool => $this->access->canView($user, $recipe)))
            ->keyBy('id');

        [$included, $excluded] = $entries->partition(fn (MealPlanEntry $entry): bool => $includable->has($entry->recipe_id));

        if ($included->isEmpty()) {
            return ['lines' => [], 'excluded_entry_ids' => $excluded->pluck('id')->all()];
        }

        $recipeLines = RecipeIngredient::query()
            ->whereIn('recipe_id', $included->pluck('recipe_id')->unique())
            ->with('unit')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->each(fn (RecipeIngredient $line) => $line->setRelation('recipe', $includable[$line->recipe_id]))
            ->groupBy('recipe_id');

        $confirmedSubstitutions = MealPlanEntrySubstitution::query()
            ->whereIn('meal_plan_entry_id', $included->modelKeys())
            ->with('ingredientSubstitution')
            ->get()
            ->filter(fn (MealPlanEntrySubstitution $confirmation): bool => $confirmation->ingredientSubstitution !== null
                && $confirmation->ingredientSubstitution->status === 'approved'
                && ($confirmation->ingredientSubstitution->recipe_id === null
                    || (int) $confirmation->ingredientSubstitution->recipe_id === (int) $confirmation->recipe_id))
            ->keyBy(fn (MealPlanEntrySubstitution $confirmation): string => $confirmation->meal_plan_entry_id.':'.$confirmation->recipe_ingredient_id);

        $requests = [];
        $meta = [];

        foreach ($included as $entry) {
            foreach ($recipeLines->get($entry->recipe_id, collect()) as $line) {
                $confirmation = $confirmedSubstitutions->get($entry->getKey().':'.$line->getKey());

                if (
                    $confirmation !== null
                    && (
                        (int) $confirmation->ingredientSubstitution->ingredient_id !== (int) $line->ingredient_id
                        || (int) $confirmation->ingredientSubstitution->replacement_ingredient_id
                            !== (int) $confirmation->replacement_ingredient_id
                    )
                ) {
                    $confirmation = null;
                }

                $meta[] = [$line, $entry->getKey(), $confirmation];

                if ($confirmation === null) {
                    $requests[count($meta) - 1] = [$line, $entry->servings];
                }
            }
        }

        $scaled = $this->scaling->adjustMany($requests);

        $lines = collect($meta)->map(function (array $item, int $index) use ($scaled): QuantityLine {
            /** @var ?MealPlanEntrySubstitution $confirmation */
            $confirmation = $item[2];

            return new QuantityLine(
                ingredientId: $confirmation?->replacement_ingredient_id ?? $item[0]->ingredient_id,
                unit: $confirmation === null ? $item[0]->unit : null,
                quantity: $confirmation === null ? $scaled[$index]['quantity'] : null,
                quantityMax: $confirmation === null ? $scaled[$index]['quantity_max'] : null,
                isOptional: $item[0]->is_optional,
                source: ['entry_id' => $item[1], 'recipe_id' => $item[0]->recipe_id],
            );
        })->values()->all();

        return ['lines' => $lines, 'excluded_entry_ids' => $excluded->pluck('id')->all()];
    }

    /**
     * @return Collection<int, User>
     */
    private function audience(MealPlan $plan): Collection
    {
        if ($plan->isPersonal()) {
            return User::query()->whereKey($plan->created_by)->get();
        }

        return User::query()->whereIn('id', $plan->household->members()->select('user_id'))->get();
    }
}
