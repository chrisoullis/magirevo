<?php

namespace App\Actions;

use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use Illuminate\Support\Facades\DB;

class SyncRecipeContent
{
    private const STEP_NUMBER_OFFSET = 10000;

    /**
     * @param  array<int, array<string, mixed>>  $steps
     * @param  array<int, array<string, mixed>>  $ingredients
     */
    public function handle(Recipe $recipe, array $steps, array $ingredients): void
    {
        DB::transaction(function () use ($recipe, $steps, $ingredients): void {
            $this->syncSteps($recipe, array_values($steps));
            $this->syncIngredients($recipe, array_values($ingredients));
        });
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function syncSteps(Recipe $recipe, array $rows): void
    {
        $keptIds = collect($rows)->pluck('id')->filter()->all();

        $recipe->steps()->whereNotIn('id', $keptIds)->get()->each->delete();

        // Moves surviving steps out of the way so reordering cannot violate the unique step number.
        $recipe->steps()->increment('step_number', self::STEP_NUMBER_OFFSET);

        foreach ($rows as $index => $row) {
            $step = filled($row['id'] ?? null)
                ? $recipe->steps()->findOrFail($row['id'])
                : new RecipeStep(['recipe_id' => $recipe->id]);

            $step->fill([
                'step_number' => $index + 1,
                'duration_minutes' => filled($row['duration_minutes'] ?? null) ? (int) $row['duration_minutes'] : null,
                'en' => ['instruction' => $row['instruction_en']],
                'el' => ['instruction' => $row['instruction_el']],
            ])->save();
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function syncIngredients(Recipe $recipe, array $rows): void
    {
        $keptIds = collect($rows)->pluck('id')->filter()->all();

        $recipe->recipeIngredients()->whereNotIn('id', $keptIds)->get()->each->delete();

        foreach ($rows as $index => $row) {
            $ingredient = filled($row['id'] ?? null)
                ? $recipe->recipeIngredients()->findOrFail($row['id'])
                : new RecipeIngredient(['recipe_id' => $recipe->id]);

            $ingredient->fill([
                'ingredient_id' => $row['ingredient_id'],
                'quantity' => filled($row['quantity'] ?? null) ? $row['quantity'] : null,
                'quantity_max' => filled($row['quantity_max'] ?? null) ? $row['quantity_max'] : null,
                'unit_id' => filled($row['unit_id'] ?? null) ? $row['unit_id'] : null,
                'scaling_mode' => $row['scaling_mode'] ?? 'linear',
                'is_optional' => (bool) ($row['is_optional'] ?? false),
                'sort_order' => $index,
                'en' => [
                    'preparation_note' => $this->nullIfBlank($row['preparation_note_en'] ?? null),
                    'quantity_text' => $this->nullIfBlank($row['quantity_text_en'] ?? null),
                ],
                'el' => [
                    'preparation_note' => $this->nullIfBlank($row['preparation_note_el'] ?? null),
                    'quantity_text' => $this->nullIfBlank($row['quantity_text_el'] ?? null),
                ],
            ])->save();
        }
    }

    private function nullIfBlank(?string $value): ?string
    {
        return filled($value) ? $value : null;
    }
}
