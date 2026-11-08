<?php

namespace App\Services;

use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeRevision;
use App\Models\RecipeRevisionDependency;
use App\Models\User;
use App\Notifications\RecipeComponentUnavailable;
use Illuminate\Support\Facades\DB;

class RecipeComponentInvalidationService
{
    /** Reverse closure includes frozen revisions as well as current live lines. */
    public function dependentRecipeIds(int $sourceId): array
    {
        $seen = [$sourceId];
        $frontier = [$sourceId];
        $parents = [];
        while ($frontier !== []) {
            $revisionIds = RecipeRevisionDependency::whereIn('component_recipe_id', $frontier)->pluck('recipe_revision_id');
            $next = RecipeRevision::whereIn('id', $revisionIds)->pluck('recipe_id')
                ->merge(RecipeIngredient::whereIn('component_recipe_id', $frontier)->pluck('recipe_id'))
                ->map(fn ($id): int => (int) $id)->unique()->diff($seen)->values()->all();
            $parents = [...$parents, ...$next];
            $seen = [...$seen, ...$next];
            $frontier = $next;
        }

        return $parents;
    }

    /** Public graph safety follows the published immutable subject, even in monitor mode. */
    public function publicGraphAvailable(Recipe $recipe): bool
    {
        $subject = $recipe;
        if ($recipe->current_revision_id !== null) {
            $subject = RecipeRevision::where('recipe_id', $recipe->id)->find($recipe->current_revision_id);
            if ($subject === null || $subject->getRawOriginal('status') !== 'published'
                || $subject->published_at === null
                || ! hash_equals($subject->content_hash, app(RecipeRevisionSnapshotter::class)->hash($subject->payload))) {
                return false;
            }
            $subject->setRelation('recipe', $recipe);
        }

        return ! app(RecipeComponentDietaryTraversal::class)->inspect($subject, false)['unresolved'];
    }

    /** Recomputed on reads: live rows cannot hide dependencies retained in the current revision. */
    public function publiclyBlockedRecipeIds(): array
    {
        $liveIds = RecipeIngredient::where('line_type', 'recipe')->distinct()->pluck('recipe_id');
        $retainedIds = Recipe::query()->whereExists(fn ($query) => $query->selectRaw('1')
            ->from('recipe_revision_dependencies')
            ->whereColumn('recipe_revision_dependencies.recipe_revision_id', 'recipes.current_revision_id'))
            ->pluck('id');
        $ids = $liveIds->merge($retainedIds)->unique();

        return Recipe::whereIn('id', $ids)->get()->filter(
            fn (Recipe $recipe): bool => ! $this->publicGraphAvailable($recipe)
        )->modelKeys();
    }

    public function schedule(int $sourceId, bool $accessChanged = false): void
    {
        DB::afterCommit(function () use ($sourceId, $accessChanged): void {
            foreach ($this->dependentRecipeIds($sourceId) as $parentId) {
                app(DietaryClaimService::class)->scheduleRevalidation($parentId, 'A retained recipe component or its verification evidence became unavailable or changed.');
                $parent = Recipe::find($parentId);
                if (! $accessChanged || ! $parent) {
                    continue;
                }
                $available = $parent->visibility?->value === 'public'
                    ? $this->publicGraphAvailable($parent)
                    : ! app(RecipeComponentDietaryTraversal::class)->inspect($parent, false)['unresolved'];
                if ($available) {
                    continue;
                }
                app(AuditLogger::class)->record('recipe_component_unavailable', ['recipe_id' => $parentId]);
                $editors = User::permission('Update:Recipe')->get();
                if ($parent->author !== null) {
                    $editors->push($parent->author);
                }
                foreach ($editors->unique('id') as $editor) {
                    if ($editor->hasVerifiedEmail() && $editor->can('update', $parent)) {
                        $editor->notify(new RecipeComponentUnavailable($parentId));
                    }
                }
            }
        });
    }
}
