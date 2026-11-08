<?php

namespace App\Services\Recipes;

use App\Models\Recipe;
use App\Models\RecipeRevision;
use App\Models\User;
use App\Services\RecipeAccessService;
use App\Services\RecipeComponentResolver;
use App\Services\RecipeRevisionService;
use App\Support\AppPermissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class RecipeComponentEligibility
{
    public function source(?User $actor, mixed $id, ?int $parentId = null): ?Recipe
    {
        if (! config('nested-recipes.enabled') || ! is_numeric($id) || (int) $id === $parentId) {
            return null;
        }
        $recipe = Recipe::find($id);

        return $recipe && $recipe->status !== 'archived' && app(RecipeAccessService::class)->canView($actor, $recipe) ? $recipe : null;
    }

    public function label(?User $actor, mixed $id, ?int $parentId = null, mixed $revisionId = null): ?string
    {
        if (! config('nested-recipes.enabled') || ! is_numeric($id) || (int) $id === $parentId) {
            return null;
        }
        $recipe = Recipe::find($id);
        if (! $recipe || $recipe->status === 'archived' || ! app(RecipeAccessService::class)->canViewDirect($actor, $recipe)) {
            return null;
        }
        if (filled($revisionId)) {
            if (! is_numeric($revisionId)) {
                return null;
            }
            $revision = RecipeRevision::where('recipe_id', $recipe->id)->find($revisionId);
            $revision?->setRelation('recipe', $recipe);
            if (! $revision || ! app(RecipeComponentResolver::class)->canResolve($revision, $actor)) {
                return null;
            }

            return $this->snapshotLabel($revision);
        }
        if (! app(RecipeAccessService::class)->canView($actor, $recipe)) {
            return null;
        }

        $recipe->loadMissing(['translations', 'yields.unit.translations', 'currentRevision']);

        return $this->recipeLabel($recipe);
    }

    public function search(?User $actor, string $search, ?int $parentId = null): array
    {
        if (! config('nested-recipes.enabled')) {
            return [];
        }
        $candidates = Recipe::query()->where('status', '!=', 'archived')->where(function (Builder $query) use ($actor): void {
            $query->where(fn (Builder $public) => $public->where('visibility', 'public')->where('status', 'published')->whereNotNull('published_at')->where('published_at', '<=', now()));
            if (! $actor?->hasVerifiedEmail()) {
                return;
            }
            $query->orWhere('author_id', $actor->id);
            if ($actor->can(AppPermissions::RECIPES_VIEW_SHARED)) {
                $query->orWhere(fn (Builder $shared) => $shared->where('visibility', 'shared')->whereHas('shares', fn (Builder $shares) => $shares->active()->where('user_id', $actor->id)));
            }
            if ($actor->can('View:Recipe')) {
                if (app(RecipeAccessService::class)->canModeratePrivate($actor)) {
                    $query->orWhereRaw('1 = 1');
                } else {
                    $query->orWhere('visibility', 'public')->orWhere('status', 'pending');
                }
            }
        })
            ->when($parentId !== null, fn (Builder $query) => $query->whereKeyNot($parentId))
            ->whereHas('translations', fn (Builder $query) => $query->where('title', 'ilike', '%'.$search.'%'))
            ->with(['translations', 'yields.unit.translations', 'currentRevision'])
            ->orderBy('id')->limit(100)->get();
        $results = [];
        foreach ($candidates as $recipe) {
            if (! app(RecipeAccessService::class)->canView($actor, $recipe)) {
                continue;
            }
            $results[$recipe->id] = $this->recipeLabel($recipe);
            if (count($results) === 50) {
                break;
            }
        }

        return $results;
    }

    private function recipeLabel(Recipe $recipe): string
    {
        if ($recipe->isPubliclyAccessible() && $recipe->currentRevision) {
            return $this->snapshotLabel($recipe->currentRevision);
        }
        $yields = $recipe->yields->map(fn ($yield): string => $yield->quantity.' '.($yield->unit?->translate(app()->getLocale())?->abbreviation ?? $yield->unit?->code ?? ''))->implode(' · ');

        return ($recipe->translate(app()->getLocale())?->title ?? $recipe->translate('en')?->title ?? '#'.$recipe->id).' · '.$recipe->base_servings.' '.__('nested.servings').($yields !== '' ? ' · '.$yields : '');
    }

    private function snapshotLabel(RecipeRevision $revision): string
    {
        $payload = $revision->payload;
        $title = $payload['translations'][app()->getLocale()]['title'] ?? $payload['translations']['en']['title'] ?? '#'.$revision->recipe_id;
        $yields = collect($payload['yields'] ?? [])->map(fn (array $yield): string => $yield['quantity'].' '.($payload['units'][$yield['unit_id']]['code'] ?? ''))->implode(' · ');

        return $title.' · '.($payload['recipe']['base_servings'] ?? '').' '.__('nested.servings').($yields !== '' ? ' · '.$yields : '');
    }

    public function pin(User $actor, array $line, ?array $existing, ?int $parentId): int
    {
        if (! config('nested-recipes.enabled')) {
            throw ValidationException::withMessages(['ingredients_data' => __('nested.disabled')]);
        }
        $requested = $line['component_recipe_revision_id'] ?? null;
        $source = filled($requested) ? Recipe::find($line['component_recipe_id'] ?? null) : $this->source($actor, $line['component_recipe_id'] ?? null, $parentId);
        if (! $source || $source->status === 'archived' || $source->id === $parentId || ! app(RecipeAccessService::class)->canViewDirect($actor, $source)) {
            throw ValidationException::withMessages(['ingredients_data' => __('nested.unavailable')]);
        }
        if ($requested) {
            $revision = RecipeRevision::where('recipe_id', $source->id)->find($requested);
            $status = $revision?->getRawOriginal('status');
            $trusted = $existing && (int) ($existing['component_recipe_id'] ?? 0) === $source->id
                && (int) ($existing['component_recipe_revision_id'] ?? 0) === (int) $requested;
            if (! $revision || (! in_array($status, ['published', 'superseded'], true) && ! ($trusted && $status === 'retained'))) {
                throw ValidationException::withMessages(['ingredients_data' => __('nested.unavailable')]);
            }

            return $revision->id;
        }
        if ($source->isPubliclyAccessible()) {
            $revision = RecipeRevision::where('recipe_id', $source->id)->where('status', 'published')->find($source->current_revision_id);
            if (! $revision) {
                throw ValidationException::withMessages(['ingredients_data' => __('nested.unavailable')]);
            }

            return $revision->id;
        }

        return app(RecipeRevisionService::class)->captureComponentSnapshot($actor, $source)->id;
    }
}
