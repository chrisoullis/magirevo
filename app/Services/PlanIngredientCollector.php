<?php

namespace App\Services;

use App\Models\MealPlan;
use App\Models\MealPlanEntryComponentMode;
use App\Models\MealPlanEntrySubstitution;
use App\Models\Recipe;
use App\Models\RecipeRevision;
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

        $resolver = app(RecipeComponentResolver::class);
        $revisions = RecipeRevision::query()->whereIn('id', $entries->pluck('planned_recipe_revision_id')->filter())->with('recipe')->get()->keyBy('id');
        $modes = MealPlanEntryComponentMode::query()->whereIn('meal_plan_entry_id', $entries->modelKeys())->get()->groupBy('meal_plan_entry_id');
        $confirmations = MealPlanEntrySubstitution::query()->whereIn('meal_plan_entry_id', $entries->modelKeys())->with('ingredientSubstitution')->get()
            ->keyBy(fn ($choice) => $choice->meal_plan_entry_id.':'.$choice->recipe_ingredient_id);
        $lines = [];
        $excluded = [];
        foreach ($entries as $entry) {
            $subject = $entry->planned_recipe_revision_id !== null ? $revisions->get($entry->planned_recipe_revision_id) : $entry->recipe;
            if ($subject === null || $audience->isEmpty() || ! $audience->every(fn (User $user): bool => $resolver->canResolve($subject, $user))) {
                $excluded[] = $entry->id;

                continue;
            }
            $result = $resolver->resolve($subject, $audience->first(), (string) $entry->servings,
                $modes->get($entry->id, collect())->pluck('mode', 'path')->all());
            if ($result['unresolved'] !== []) {
                $excluded[] = $entry->id;

                continue;
            }
            foreach ($result['lines'] as $line) {
                $source = (array) $line->source;
                $confirmation = $confirmations->get($entry->id.':'.($source['recipe_ingredient_id'] ?? ''));
                $substitution = $confirmation?->ingredientSubstitution;
                $valid = $substitution !== null && $substitution->status === 'approved'
                    && (int) $substitution->ingredient_id === $line->ingredientId
                    && (int) $substitution->replacement_ingredient_id === (int) $confirmation->replacement_ingredient_id
                    && $substitution->appliesToResolvedSource($source);
                $lines[] = new QuantityLine(
                    ingredientId: $valid ? $confirmation->replacement_ingredient_id : $line->ingredientId,
                    unit: $valid ? null : $line->unit,
                    quantity: $valid ? null : $line->quantity,
                    quantityMax: $valid ? null : $line->quantityMax,
                    isOptional: $line->isOptional,
                    source: [...$source, 'entry_id' => $entry->id, 'servings' => (string) $entry->servings, 'planned_recipe_revision_id' => $entry->planned_recipe_revision_id],
                );
            }
        }

        return ['lines' => $lines, 'excluded_entry_ids' => $excluded];
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
