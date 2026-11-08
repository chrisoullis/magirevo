<?php

namespace App\Services\Recipes;

use App\Models\Recipe;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Domain operations; editor entry points must authorize the actor before calling. */
class RecipeStepGroups
{
    /** Ungrouped steps first, then groups by position/id, with global step_number preserved within each. */
    public function orderedSteps(Recipe $recipe): Collection
    {
        $groups = $recipe->stepGroups()->pluck('id')->flip();

        return $recipe->steps()->get()->sortBy(fn ($step): array => [
            $step->recipe_step_group_id === null ? -1 : $groups[$step->recipe_step_group_id],
            $step->step_number,
            $step->id,
        ])->values();
    }

    /** @return array<string|int, int> Form keys or snapshot indexes mapped to live group IDs. */
    public function sync(Recipe $recipe, array $groups): array
    {
        if (! Schema::hasTable('recipe_step_groups')) {
            if ($groups !== []) {
                throw ValidationException::withMessages(['step_groups_data' => __('cooking.groups_not_ready')]);
            }

            return [];
        }
        $ids = [];
        foreach (array_values($groups) as $index => $row) {
            $group = filled($row['id'] ?? null) ? $recipe->stepGroups()->findOrFail($row['id']) : $recipe->stepGroups()->make();
            $group->position = $index;
            foreach (['en', 'el'] as $locale) {
                $group->translateOrNew($locale)->fill([
                    'title' => trim((string) ($row['translations'][$locale]['title'] ?? '')),
                    'description' => filled($row['translations'][$locale]['description'] ?? null) ? trim($row['translations'][$locale]['description']) : null,
                ]);
            }
            $group->save();
            $ids[$row['key'] ?? $index] = $group->id;
        }

        return $ids;
    }

    /** Call only after reassigning retained steps; unknown callers cannot silently remove content. */
    public function removeUnused(Recipe $recipe, array $keptIds): void
    {
        if (! Schema::hasTable('recipe_step_groups')) {
            return;
        }
        $unused = $recipe->stepGroups()->whereNotIn('id', $keptIds)->pluck('id');
        $recipe->steps()->whereIn('recipe_step_group_id', $unused)->update(['recipe_step_group_id' => null]);
        $recipe->stepGroups()->whereIn('id', $unused)->get()->each->delete();
    }

    /** Moves all steps before removing a group; media and stable step IDs remain untouched. */
    public function remove(Recipe $recipe, int $groupId, bool $confirmed, ?int $destinationId = null): void
    {
        if (! $confirmed || $destinationId === $groupId) {
            throw ValidationException::withMessages(['step_groups' => __('cooking.confirm_group_removal')]);
        }
        DB::transaction(function () use ($recipe, $groupId, $destinationId): void {
            $recipe = Recipe::query()->lockForUpdate()->findOrFail($recipe->id);
            $group = $recipe->stepGroups()->lockForUpdate()->findOrFail($groupId);
            if ($destinationId !== null) {
                $recipe->stepGroups()->lockForUpdate()->findOrFail($destinationId);
            }
            $group->steps()->update(['recipe_step_group_id' => $destinationId]);
            $group->delete();
        });
    }
}
