<?php

namespace App\Services\Recipes;

use App\Actions\SyncRecipeContent;
use App\Models\Recipe;
use App\Models\RecipeRevision;
use App\Models\User;
use App\Services\RecipeAccessService;
use App\Services\RecipePublishingService;
use App\Services\RecipeQuotaService;
use App\Services\RecipeRevisionService;
use App\Services\RecipeRevisionSnapshotter;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Application boundary shared by panel and contributor presentations. */
class RecipeEditorService
{
    public function __construct(private RecipeEditorValidator $validator, private SyncRecipeContent $content) {}

    public function create(User $actor, array $data): Recipe
    {
        Gate::forUser($actor)->authorize('create', Recipe::class);

        return app(RecipeQuotaService::class)->withinPersonalQuota($actor, function () use ($actor, $data): Recipe {
            $validated = $this->validator->validate($data, new Recipe, $actor);
            $recipe = Recipe::query()->create([
                ...$this->attributes($validated), 'author_id' => $actor->id, 'status' => 'draft', 'visibility' => 'private',
            ]);
            $this->persist($recipe, $validated);
            $this->saveEditorialFields($actor, $recipe, $data);

            return $recipe->refresh();
        });
    }

    public function update(User $actor, Recipe $recipe, array $data, string $expectedVersion): Recipe
    {
        return DB::transaction(function () use ($actor, $recipe, $data, $expectedVersion): Recipe {
            $recipe = Recipe::query()->lockForUpdate()->findOrFail($recipe->id);
            Gate::forUser($actor)->authorize('update', $recipe);
            if (app(RecipeRevisionService::class)->isLive($recipe) || $recipe->status === 'pending') {
                throw ValidationException::withMessages(['recipe' => __('editor.revision_required')]);
            }
            if (! hash_equals($this->version($recipe), $expectedVersion)) {
                throw ValidationException::withMessages(['draft' => __('editor.stale')]);
            }
            $validated = $this->validator->validate($data, $recipe, $actor);
            $recipe->update($this->attributes($validated));
            $this->persist($recipe, $validated);
            $this->saveEditorialFields($actor, $recipe, $data);

            return $recipe->refresh();
        });
    }

    public function saveRevision(User $actor, RecipeRevision $revision, array $data, string $expectedVersion): RecipeRevision
    {
        return DB::transaction(function () use ($actor, $revision, $data, $expectedVersion): RecipeRevision {
            Gate::forUser($actor)->authorize('edit', $revision);
            $data = $this->validator->validate($data, $revision->recipe, $actor, $revision->payload);
            $snapshotter = app(RecipeRevisionSnapshotter::class);
            $payload = RecipeEditorData::toPayload($revision->payload, $data);
            $allowedMedia = collect($snapshotter->mediaReferences($revision->payload))->pluck('media_id')->all();
            if (collect($snapshotter->mediaReferences($payload))->pluck('media_id')->diff($allowedMedia)->isNotEmpty()) {
                throw ValidationException::withMessages(['media_data' => __('site.step_media_invalid')]);
            }

            return app(RecipeRevisionService::class)->saveDraft($actor, $revision, $payload, $expectedVersion);
        });
    }

    public function submit(User $actor, Recipe|RecipeRevision $target): Recipe|RecipeRevision
    {
        return $target instanceof RecipeRevision
            ? app(RecipeRevisionService::class)->submit($actor, $target)
            : app(RecipePublishingService::class)->submit($actor, $target);
    }

    public function version(Recipe $recipe): string
    {
        $snapshotter = app(RecipeRevisionSnapshotter::class);
        $payload = $snapshotter->serialize($recipe->id);

        return hash('sha256', json_encode([$payload, $snapshotter->snapshotMedia($payload)], JSON_THROW_ON_ERROR));
    }

    private function attributes(array $data): array
    {
        return Arr::only($data, ['en', 'el', 'difficulty', 'prep_minutes', 'cook_minutes', 'rest_minutes', 'base_servings']);
    }

    private function persist(Recipe $recipe, array $data): void
    {
        $recipe->categories()->sync($data['categories'] ?? $recipe->categories()->pluck('categories.id')->all());
        if (array_key_exists('tags', $data)) {
            $recipe->tags()->sync(RecipeEditorData::mergeTagIds($recipe->tags()->pluck('tags.id')->all(), $data['tags']));
        }
        $this->content->handle($recipe, $data['steps_data'], $data['ingredients_data'], $data['ingredient_groups_data'] ?? null, $data['step_groups_data'] ?? null);
        if (array_key_exists('yields_data', $data)) {
            $recipe->yields()->whereNotIn('basis', array_column($data['yields_data'], 'basis'))->delete();
            foreach ($data['yields_data'] as $yield) {
                $recipe->yields()->updateOrCreate(['basis' => $yield['basis']], Arr::only($yield, ['quantity', 'unit_id', 'evidence', 'verified_by', 'verified_at']));
            }
        }
    }

    private function saveEditorialFields(User $actor, Recipe $recipe, array $data): void
    {
        if (array_key_exists('is_featured', $data) && app(RecipeAccessService::class)->canManage($actor, $recipe)) {
            $recipe->update(['is_featured' => (bool) $data['is_featured']]);
        }
    }
}
