<?php

namespace App\Services\Recipes;

use App\Actions\RemoveRecipeMedia;
use App\Actions\StoreMedia;
use App\Actions\StoreRecipeStepImage;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\RecipeRevision;
use App\Models\RecipeRevisionAudit;
use App\Models\User;
use App\Services\RecipeRevisionService;
use App\Services\RecipeRevisionSnapshotter;
use App\Support\TrustedVideoUrl;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Revision uploads are private new assets; published relationships and metadata are never mutated. */
class RecipeEditorMedia
{
    public function remove(User $actor, Recipe|RecipeRevision $target, int $mediaId, string $expectedVersion): void
    {
        Gate::forUser($actor)->authorize($target instanceof RecipeRevision ? 'edit' : 'update', $target);
        if ($target instanceof RecipeRevision) {
            $payload = $target->payload;
            $references = collect(app(RecipeRevisionSnapshotter::class)->mediaReferences($payload))->pluck('media_id');
            abort_unless($references->contains($mediaId), 404);
            $payload['media'] = array_values(array_filter($payload['media'], fn ($item) => $item['media_id'] !== $mediaId));
            foreach ($payload['steps'] as &$step) {
                $step['media'] = array_values(array_filter($step['media'], fn ($item) => $item['media_id'] !== $mediaId));
            }
            app(RecipeRevisionService::class)->saveDraft($actor, $target, $payload, $expectedVersion);

            return;
        }
        DB::transaction(function () use ($actor, $target, $mediaId, $expectedVersion): void {
            $recipe = Recipe::query()->lockForUpdate()->findOrFail($target->id);
            Gate::forUser($actor)->authorize('update', $recipe);
            $service = app(RecipeEditorService::class);
            if (app(RecipeRevisionService::class)->isLive($recipe) || $recipe->status === 'pending') {
                throw ValidationException::withMessages(['recipe' => __('editor.revision_required')]);
            }
            if (! hash_equals($service->version($recipe), $expectedVersion)) {
                throw ValidationException::withMessages(['draft' => __('editor.stale')]);
            }
            $media = $recipe->media()->whereKey($mediaId)->first();
            if ($media) {
                app(RemoveRecipeMedia::class)->handle($recipe, $media);

                return;
            }
            $step = $recipe->steps()->whereHas('media', fn ($query) => $query->whereKey($mediaId))->firstOrFail();
            $isVideo = $step->media()->whereKey($mediaId)->firstOrFail()->pivot->kind === 'video';
            $data = RecipeEditorData::fromRecipe($recipe);
            foreach ($data['steps_data'] as &$row) {
                if ($row['id'] === $step->id) {
                    $row['media_items'] = array_values(array_filter($row['media_items'], fn ($item) => $item['media_id'] !== $mediaId));
                    if ($isVideo) {
                        $row['video_url'] = null;
                    }
                }
            }
            $service->update($actor, $recipe, $data, $expectedVersion);
        });
    }

    public function video(User $actor, Recipe|RecipeRevision $target, int $stepIndex, string $url, array $translations, string $expectedVersion): void
    {
        Gate::forUser($actor)->authorize($target instanceof RecipeRevision ? 'edit' : 'update', $target);
        Validator::make(compact('url', 'translations'), ['url' => ['required', 'url', 'max:2048'], 'translations.en.alt_text' => ['required', 'string', 'max:255'], 'translations.el.alt_text' => ['required', 'string', 'max:255']])->validate();
        $embed = TrustedVideoUrl::embedUrl($url);
        if ($embed === null) {
            throw ValidationException::withMessages(['url' => __('site.video_url_invalid')]);
        }
        if ($target instanceof Recipe) {
            $data = RecipeEditorData::fromRecipe($target);
            if (! isset($data['steps_data'][$stepIndex])) {
                throw ValidationException::withMessages(['stepIndex' => __('site.step_media_invalid')]);
            }
            $data['steps_data'][$stepIndex]['video_url'] = $url;
            foreach (['en', 'el'] as $locale) {
                $data['steps_data'][$stepIndex]['video_alt_text_'.$locale] = $translations[$locale]['alt_text'];
            }
            app(RecipeEditorService::class)->update($actor, $target, $data, $expectedVersion);

            return;
        }
        DB::transaction(function () use ($actor, $target, $stepIndex, $embed, $translations, $expectedVersion): void {
            Recipe::query()->whereKey($target->recipe_id)->lockForUpdate()->firstOrFail();
            $revision = RecipeRevision::query()->lockForUpdate()->findOrFail($target->id);
            Gate::forUser($actor)->authorize('edit', $revision);
            if (! isset($revision->payload['steps'][$stepIndex])) {
                throw ValidationException::withMessages(['stepIndex' => __('site.step_media_invalid')]);
            }
            $media = Media::query()->create(['disk' => Media::REMOTE_DISK, 'media_type' => Media::TYPE_VIDEO, 'source' => Media::SOURCE_EMBED, 'url' => $embed, 'mime_type' => 'video/embed']);
            foreach (['en', 'el'] as $locale) {
                $media->translations()->create(['locale' => $locale, 'alt_text' => $translations[$locale]['alt_text']]);
            }
            $payload = $revision->payload;
            $payload['steps'][$stepIndex]['media'] = array_values(array_filter($payload['steps'][$stepIndex]['media'], fn ($item) => $item['kind'] !== 'video'));
            $payload['steps'][$stepIndex]['media'][] = ['media_id' => $media->id, 'kind' => 'video', 'sort_order' => count($payload['steps'][$stepIndex]['media'])];
            $service = app(RecipeRevisionService::class);
            $saved = $service->saveDraft($actor, $revision, $payload, $expectedVersion);
            $service->retainMedia($saved);
        });
    }

    public function uploadDraft(User $actor, Recipe $recipe, UploadedFile $file, array $translations, string $role, string $expectedVersion, bool $rightsConfirmed, ?int $stepIndex = null): void
    {
        Gate::forUser($actor)->authorize('update', $recipe);
        Validator::make(compact('role', 'rightsConfirmed'), ['role' => ['required', 'in:cover,gallery,step'], 'rightsConfirmed' => ['accepted']])->validate();
        $within = function (Media $media) use ($actor, $recipe, $role, $expectedVersion, $stepIndex): void {
            $recipe = Recipe::query()->lockForUpdate()->findOrFail($recipe->id);
            Gate::forUser($actor)->authorize('update', $recipe);
            $service = app(RecipeEditorService::class);
            if (app(RecipeRevisionService::class)->isLive($recipe) || $recipe->status === 'pending') {
                throw ValidationException::withMessages(['recipe' => __('editor.revision_required')]);
            }
            if (! hash_equals($service->version($recipe), $expectedVersion)) {
                throw ValidationException::withMessages(['draft' => __('editor.stale')]);
            }
            if ($role === 'step') {
                $step = $stepIndex === null ? null : $recipe->steps()->orderBy('step_number')->get()->get($stepIndex);
                if (! $step || $step->media()->wherePivot('kind', 'image')->count() >= 3) {
                    throw ValidationException::withMessages(['stepIndex' => __('site.step_media_invalid')]);
                }
                $step->media()->attach($media, ['kind' => 'image', 'sort_order' => $step->media()->count()]);
            } else {
                if ($role === 'cover') {
                    $recipe->media()->wherePivot('role', 'cover')->get()->each(fn ($item) => $recipe->media()->updateExistingPivot($item->id, ['role' => 'gallery']));
                }
                $recipe->media()->attach($media, ['role' => $role, 'sort_order' => $recipe->media()->count()]);
            }
            RecipeRevisionAudit::query()->create(['recipe_id' => $recipe->id, 'actor_id' => $actor->id, 'action' => 'media_uploaded', 'metadata' => ['media_id' => $media->id, 'rights_confirmed' => true]]);
        };
        if ($role === 'step') {
            app(StoreRecipeStepImage::class)->store($file, $translations, $within, 'local');
        } else {
            app(StoreMedia::class)->handle($file, $translations, 'recipe-media', $within, 'local');
        }
    }

    public function upload(User $actor, RecipeRevision $revision, UploadedFile $file, array $translations, string $role, string $expectedVersion, bool $rightsConfirmed, ?int $stepIndex = null): RecipeRevision
    {
        Gate::forUser($actor)->authorize('edit', $revision);
        Validator::make(compact('role', 'rightsConfirmed', 'translations'), [
            'role' => ['required', 'in:cover,gallery,step'],
            'rightsConfirmed' => ['accepted'],
            'translations' => ['required', 'array:en,el'],
            'translations.*' => ['required', 'array:alt_text,caption'],
            'translations.*.alt_text' => ['required', 'string', 'max:255'],
            'translations.*.caption' => ['nullable', 'string', 'max:1000'],
        ])->validate();

        if ($revision->recipe->derived_from_recipe_id !== null) {
            app(RecipeVariationMedia::class)->assertNotSourceMedia($revision->recipe, $file);
        }

        // StoreMedia removes its file if authorization, locking or revision persistence fails in this callback.
        $saved = null;
        $within = function (Media $media) use ($actor, $revision, $role, $expectedVersion, $stepIndex, &$saved): void {
            DB::table('recipes')->where('id', $revision->recipe_id)->lockForUpdate()->first();
            $revision = RecipeRevision::query()->lockForUpdate()->findOrFail($revision->id);
            Gate::forUser($actor)->authorize('edit', $revision);
            $payload = $revision->payload;
            if ($role === 'step') {
                if ($stepIndex === null || ! isset($payload['steps'][$stepIndex])) {
                    throw ValidationException::withMessages(['stepIndex' => __('site.step_media_invalid')]);
                }
                if (collect($payload['steps'][$stepIndex]['media'])->where('kind', 'image')->count() >= 3) {
                    throw ValidationException::withMessages(['stepIndex' => __('site.step_media_invalid')]);
                }
                $payload['steps'][$stepIndex]['media'][] = ['media_id' => $media->id, 'kind' => 'image', 'sort_order' => count($payload['steps'][$stepIndex]['media'])];
            } else {
                if ($role === 'cover') {
                    foreach ($payload['media'] as &$item) {
                        if ($item['role'] === 'cover') {
                            $item['role'] = 'gallery';
                        }
                    }
                    unset($item);
                }
                $payload['media'][] = ['media_id' => $media->id, 'role' => $role, 'sort_order' => count($payload['media'])];
            }
            $service = app(RecipeRevisionService::class);
            $saved = $service->saveDraft($actor, $revision, $payload, $expectedVersion);
            $service->retainMedia($saved);
            $service->audit($saved, $actor->id, 'media_uploaded', $saved->status, $saved->status, null, ['media_id' => $media->id, 'rights_confirmed' => true]);
        };
        if ($role === 'step') {
            app(StoreRecipeStepImage::class)->store($file, $translations, $within, 'local');
        } else {
            app(StoreMedia::class)->handle($file, $translations, 'recipe-revision-media', $within, 'local');
        }

        return $saved;
    }
}
