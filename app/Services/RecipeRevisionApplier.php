<?php

namespace App\Services;

use App\Models\Recipe;
use App\Services\Recipes\RecipeStepGroups;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Writes an approved revision payload onto the live recipe tables. Only the revision publisher calls it, inside
 * {@see RecipeContentGuard::asPublisher()}. Lines and steps that keep their ID are updated in place, so personal
 * ingredient changes and planned substitutions that reference them survive; removed lines are archived by the database
 * trigger and lines whose ingredient changed are archived here before their dependents are cleared.
 */
class RecipeRevisionApplier
{
    private const STEP_NUMBER_OFFSET = 10000;

    /**
     * @param  array<string, mixed>  $payload  A normalised payload.
     * @return array{ingredient_groups: array<int, int>, ingredients: array<int, int>, steps: array<int, int>}
     */
    public function apply(int $recipeId, array $payload, int $revisionId): array
    {
        $now = now();

        // UUIDs are the durable identity when restoring deleted lines.
        $liveLines = DB::table('recipe_ingredients')->where('recipe_id', $recipeId)->get()->keyBy('line_uuid');
        foreach ($payload['ingredients'] as &$line) {
            if (isset($line['line_uuid'])) {
                $line['id'] = $liveLines->get($line['line_uuid'])?->id;
            }
        }
        unset($line);
        DB::table('recipe_yields')->where('recipe_id', $recipeId)->delete();
        foreach ($payload['yields'] ?? [] as $yield) {
            $yield['evidence'] = json_encode($yield['evidence'], JSON_THROW_ON_ERROR);
            DB::table('recipe_yields')->insert(['recipe_id' => $recipeId, ...$yield, 'created_at' => $now, 'updated_at' => $now]);
        }

        DB::table('recipes')->where('id', $recipeId)->update([...$payload['recipe'], 'updated_at' => $now]);

        $this->syncTranslations($recipeId, $payload['translations'], $now);
        $this->syncPivot('recipe_category', 'category_id', $recipeId, $payload['category_ids'], $now);
        $this->syncPivot('recipe_tag', 'tag_id', $recipeId, $payload['tag_ids'], $now);
        $this->syncRecipeMedia($recipeId, $payload['media'], $now);

        $groupIds = $this->syncGroups($recipeId, $payload['ingredient_groups'], $now);
        $lineIds = $this->syncIngredients($recipeId, $payload['ingredients'], $groupIds, $revisionId, $now);
        $this->removeUnusedGroups($recipeId, $groupIds);
        $stepGroups = app(RecipeStepGroups::class);
        $recipe = Recipe::query()->findOrFail($recipeId);
        $stepGroupIds = Schema::hasTable('recipe_step_groups') ? $stepGroups->sync($recipe, $payload['step_groups'] ?? []) : null;
        $stepIds = $this->syncSteps($recipeId, $payload['steps'], $now, $stepGroupIds);
        if ($stepGroupIds !== null) {
            $stepGroups->removeUnused($recipe, array_values($stepGroupIds));
        }

        return ['ingredient_groups' => $groupIds, 'ingredients' => $lineIds, 'steps' => $stepIds, 'step_groups' => $stepGroupIds ?? []];
    }

    /**
     * @param  array<string, array<string, mixed>>  $translations
     */
    private function syncTranslations(int $recipeId, array $translations, mixed $now): void
    {
        DB::table('recipe_translations')->where('recipe_id', $recipeId)->whereNotIn('locale', array_keys($translations))->delete();

        foreach ($translations as $locale => $fields) {
            $exists = DB::table('recipe_translations')->where('recipe_id', $recipeId)->where('locale', $locale)->exists();

            if ($exists) {
                DB::table('recipe_translations')->where('recipe_id', $recipeId)->where('locale', $locale)->update([...$fields, 'updated_at' => $now]);
            } else {
                DB::table('recipe_translations')->insert(['recipe_id' => $recipeId, 'locale' => $locale, ...$fields, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    /**
     * @param  list<int>  $wanted
     */
    private function syncPivot(string $table, string $column, int $recipeId, array $wanted, mixed $now): void
    {
        DB::table($table)->where('recipe_id', $recipeId)->whereNotIn($column, $wanted)->delete();

        $present = DB::table($table)->where('recipe_id', $recipeId)->pluck($column)->map(fn ($v): int => (int) $v)->all();

        foreach (array_diff($wanted, $present) as $id) {
            DB::table($table)->insert(['recipe_id' => $recipeId, $column => $id, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    /**
     * @param  list<array{media_id: int, role: string, sort_order: int}>  $media
     */
    private function syncRecipeMedia(int $recipeId, array $media, mixed $now): void
    {
        // Replaced wholesale so a changed cover never collides with the one-cover index.
        DB::table('recipe_media')->where('recipe_id', $recipeId)->delete();

        foreach ($media as $item) {
            DB::table('recipe_media')->insert(['recipe_id' => $recipeId, ...$item, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     * @return array<int, int>
     */
    private function syncGroups(int $recipeId, array $groups, mixed $now): array
    {
        $existing = DB::table('ingredient_groups')->where('recipe_id', $recipeId)->pluck('id')->map(fn ($v): int => (int) $v)->all();
        $ids = [];

        foreach ($groups as $index => $group) {
            if ($group['id'] !== null && in_array($group['id'], $existing, true)) {
                DB::table('ingredient_groups')->where('id', $group['id'])->update(['sort_order' => $group['sort_order'], 'updated_at' => $now]);
                $id = (int) $group['id'];
            } else {
                $id = (int) DB::table('ingredient_groups')->insertGetId(['recipe_id' => $recipeId, 'sort_order' => $group['sort_order'], 'created_at' => $now, 'updated_at' => $now]);
            }

            DB::table('ingredient_group_translations')->where('ingredient_group_id', $id)->delete();

            foreach ($group['names'] as $locale => $name) {
                DB::table('ingredient_group_translations')->insert(['ingredient_group_id' => $id, 'locale' => $locale, 'name' => $name, 'created_at' => $now, 'updated_at' => $now]);
            }

            $ids[$index] = $id;
        }

        return $ids;
    }

    /**
     * @param  array<int, int>  $groupIds
     */
    private function removeUnusedGroups(int $recipeId, array $groupIds): void
    {
        DB::table('ingredient_groups')->where('recipe_id', $recipeId)->whereNotIn('id', $groupIds)->delete();
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<int, int>  $groupIds
     * @return array<int, int>
     */
    private function syncIngredients(int $recipeId, array $lines, array $groupIds, int $revisionId, mixed $now): array
    {
        $existing = DB::table('recipe_ingredients')->where('recipe_id', $recipeId)->pluck('ingredient_id', 'id')->map(fn ($v): int => (int) $v)->all();
        $kept = collect($lines)->pluck('id')->filter()->map(fn ($v): int => (int) $v)->all();

        // The delete trigger archives personal changes and substitutions that still point at these lines.
        DB::table('recipe_ingredients')->where('recipe_id', $recipeId)->whereNotIn('id', $kept)->delete();

        $ids = [];

        foreach ($lines as $index => $line) {
            $columns = [
                ...array_intersect_key($line, array_flip(['line_uuid', 'line_type', 'component_recipe_id', 'component_recipe_revision_id', 'quantity_basis', 'shopping_mode', 'prepared_ingredient_id', 'prepared_quantity_per_batch', 'prepared_unit_id'])),
                'ingredient_id' => $line['ingredient_id'],
                'ingredient_group_id' => $line['group_index'] !== null ? $groupIds[$line['group_index']] : null,
                'quantity' => $line['quantity'],
                'quantity_max' => $line['quantity_max'],
                'unit_id' => $line['unit_id'],
                'scaling_mode' => $line['scaling_mode'],
                'is_optional' => $line['is_optional'],
                'sort_order' => $line['sort_order'],
                'updated_at' => $now,
            ];

            if ($line['id'] !== null && array_key_exists($line['id'], $existing)) {
                $id = (int) $line['id'];

                if ($existing[$id] !== (int) $line['ingredient_id']) {
                    $this->archiveDependents($id, $revisionId, 'line_replaced');
                }

                DB::table('recipe_ingredients')->where('id', $id)->update($columns);
            } else {
                $id = (int) DB::table('recipe_ingredients')->insertGetId(['recipe_id' => $recipeId, ...$columns, 'created_at' => $now]);
            }

            DB::table('recipe_ingredient_translations')->where('recipe_ingredient_id', $id)->delete();

            foreach ($line['translations'] as $locale => $texts) {
                DB::table('recipe_ingredient_translations')->insert(['recipe_ingredient_id' => $id, 'locale' => $locale, ...$texts, 'created_at' => $now, 'updated_at' => $now]);
            }

            $ids[$index] = $id;
        }

        return $ids;
    }

    /**
     * A line that now names a different ingredient keeps its ID, but a swap or substitution chosen for the old
     * ingredient no longer applies. Both are kept in the archive tables rather than silently re-pointed.
     */
    private function archiveDependents(int $lineId, int $revisionId, string $reason): void
    {
        DB::statement(<<<'SQL'
INSERT INTO archived_personal_ingredient_changes
    (original_id, user_id, recipe_id, recipe_ingredient_id, alternative_ingredient_id, note, source_recipe_revision_id, retired_in_revision_id, reason, original_created_at, archived_at)
SELECT id, user_id, recipe_id, recipe_ingredient_id, alternative_ingredient_id, note, source_recipe_revision_id, ?, ?, created_at, NOW()
FROM user_recipe_ingredient_changes WHERE recipe_ingredient_id = ?
SQL, [$revisionId, $reason, $lineId]);

        DB::statement(<<<'SQL'
INSERT INTO archived_meal_plan_entry_substitutions
    (original_id, meal_plan_entry_id, recipe_id, recipe_ingredient_id, ingredient_substitution_id, replacement_ingredient_id, confirmed_by, confirmed_at, retired_in_revision_id, reason, archived_at)
SELECT id, meal_plan_entry_id, recipe_id, recipe_ingredient_id, ingredient_substitution_id, replacement_ingredient_id, confirmed_by, confirmed_at, ?, ?, NOW()
FROM meal_plan_entry_substitutions WHERE recipe_ingredient_id = ?
SQL, [$revisionId, $reason, $lineId]);

        DB::table('user_recipe_ingredient_changes')->where('recipe_ingredient_id', $lineId)->delete();
        DB::table('meal_plan_entry_substitutions')->where('recipe_ingredient_id', $lineId)->delete();
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @return array<int, int>
     */
    private function syncSteps(int $recipeId, array $steps, mixed $now, ?array $stepGroupIds = null): array
    {
        $existing = DB::table('recipe_steps')->where('recipe_id', $recipeId)->pluck('id')->map(fn ($v): int => (int) $v)->all();
        $kept = collect($steps)->pluck('id')->filter()->map(fn ($v): int => (int) $v)->all();

        DB::table('recipe_steps')->where('recipe_id', $recipeId)->whereNotIn('id', $kept)->delete();

        // Moves surviving steps out of the way so reordering cannot violate the unique step number.
        DB::table('recipe_steps')->where('recipe_id', $recipeId)->increment('step_number', self::STEP_NUMBER_OFFSET);

        $ids = [];

        foreach ($steps as $index => $step) {
            if (isset($step['step_group_index']) && ! array_key_exists($step['step_group_index'], $stepGroupIds ?? [])) {
                throw new \InvalidArgumentException('Unknown cooking step group in revision payload.');
            }
            $membership = $stepGroupIds === null ? [] : ['recipe_step_group_id' => isset($step['step_group_index']) ? $stepGroupIds[$step['step_group_index']] : null];
            $columns = [...$membership, 'step_number' => $step['step_number'], 'duration_minutes' => $step['duration_minutes'], 'updated_at' => $now];

            if ($step['id'] !== null && in_array($step['id'], $existing, true)) {
                $id = (int) $step['id'];
                DB::table('recipe_steps')->where('id', $id)->update($columns);
            } else {
                $id = (int) DB::table('recipe_steps')->insertGetId(['recipe_id' => $recipeId, ...$columns, 'created_at' => $now]);
            }

            DB::table('recipe_step_translations')->where('recipe_step_id', $id)->delete();

            foreach ($step['translations'] as $locale => $texts) {
                DB::table('recipe_step_translations')->insert(['recipe_step_id' => $id, 'locale' => $locale, ...$texts, 'created_at' => $now, 'updated_at' => $now]);
            }

            DB::table('recipe_step_media')->where('recipe_step_id', $id)->delete();

            foreach ($step['media'] as $item) {
                DB::table('recipe_step_media')->insert(['recipe_step_id' => $id, ...$item, 'created_at' => $now, 'updated_at' => $now]);
            }

            $ids[$index] = $id;
        }

        return $ids;
    }
}
