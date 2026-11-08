<?php

namespace App\Services\Recipes;

use App\Actions\DeleteMediaIfUnused;
use App\Actions\StoreRecipeMedia;
use App\Actions\StoreRecipeStepImage;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\RecipeRevisionAudit;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\TrustedVideoUrl;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class RecipeVariationMedia
{
    public function assertNotSourceMedia(Recipe $recipe, UploadedFile $file): void
    {
        foreach ($recipe->derivation->sourceRevision->media_snapshot ?? [] as $asset) {
            if (filled($asset['file_path'] ?? null) && in_array($asset['source'] ?? null, ['local'], true)) {
                foreach (['local', 'public'] as $disk) {
                    if (Storage::disk($disk)->exists($asset['file_path']) && hash_equals(hash_file('sha256', $file->getRealPath()), hash_file('sha256', Storage::disk($disk)->path($asset['file_path'])))) {
                        throw ValidationException::withMessages(['photo' => __('variations.source_media_forbidden')]);
                    }
                }
            }
        }
    }

    public function upload(User $actor, Recipe $recipe, UploadedFile $file, string $role, ?int $stepId, array $alt, bool $confirmed, string $version): Media
    {
        Validator::make(compact('role', 'alt', 'confirmed', 'file'), [
            'file' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240', Rule::dimensions()->maxWidth(6000)->maxHeight(6000)],
            'role' => ['required', 'in:cover,gallery,step'],
            'alt' => ['required', 'array:en,el'], 'alt.en' => ['required', 'string', 'max:255'], 'alt.el' => ['required', 'string', 'max:255'],
            'confirmed' => ['accepted'],
        ])->validate();
        $stored = null;
        try {
            return DB::transaction(function () use ($actor, $recipe, $file, $role, $stepId, $alt, $version, &$stored): Media {
                $recipe = Recipe::query()->whereKey($recipe->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($actor)->authorize('editVariation', $recipe);
                $this->assertVersion($recipe, $version);
                $this->assertNotSourceMedia($recipe, $file);
                $translations = ['en' => ['alt_text' => $alt['en']], 'el' => ['alt_text' => $alt['el']]];
                if ($role === 'step') {
                    $step = $recipe->steps()->findOrFail($stepId);
                    abort_if($step->media()->wherePivot('kind', 'image')->count() >= 3, 422);
                    $stored = app(StoreRecipeStepImage::class)->handle($recipe, $step, $file, $translations, $step->media()->count());
                } else {
                    if ($role === 'cover') {
                        $recipe->media()->wherePivot('role', 'cover')->get()->each(fn ($media) => $recipe->media()->updateExistingPivot($media->id, ['role' => 'gallery']));
                    }
                    $stored = app(StoreRecipeMedia::class)->handle($recipe, $file, $translations, $role);
                }
                RecipeRevisionAudit::query()->create(['recipe_id' => $recipe->id, 'actor_id' => $actor->id, 'action' => 'variation_edited']);
                app(AuditLogger::class)->record('recipe_variation_media_uploaded', ['recipe_id' => $recipe->id, 'media_id' => $stored->id, 'rights_confirmed_by' => $actor->id]);

                return $stored;
            });
        } catch (Throwable $error) {
            if ($stored?->file_path) {
                Storage::disk($stored->disk)->delete($stored->file_path);
            }
            throw $error;
        }
    }

    public function remove(User $actor, Recipe $recipe, int $mediaId, ?int $stepId, string $version): void
    {
        DB::transaction(function () use ($actor, $recipe, $mediaId, $stepId, $version): void {
            $recipe = Recipe::query()->whereKey($recipe->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('editVariation', $recipe);
            $this->assertVersion($recipe, $version);
            $relation = $stepId === null ? $recipe->media() : $recipe->steps()->findOrFail($stepId)->media();
            $media = $relation->findOrFail($mediaId);
            $relation->detach($media);
            app(DeleteMediaIfUnused::class)->handle($media);
            RecipeRevisionAudit::query()->create(['recipe_id' => $recipe->id, 'actor_id' => $actor->id, 'action' => 'variation_edited']);
            app(AuditLogger::class)->record('recipe_variation_media_removed', ['recipe_id' => $recipe->id, 'media_id' => $mediaId, 'removed_by' => $actor->id]);
        });
    }

    private function assertVersion(Recipe $recipe, string $version): void
    {
        if (! hash_equals(app(RecipeVariationContent::class)->version($recipe), $version)) {
            throw ValidationException::withMessages(['draft' => __('variations.stale')]);
        }
    }

    public function video(User $actor, Recipe $recipe, int $stepId, string $url, bool $confirmed, string $version): void
    {
        Validator::make(compact('url', 'confirmed'), ['url' => ['required', 'string', 'max:2048'], 'confirmed' => ['accepted']])->validate();
        $embed = TrustedVideoUrl::embedUrl($url);
        if ($embed === null) {
            throw ValidationException::withMessages(['videoUrl' => __('site.video_url_invalid')]);
        }
        DB::transaction(function () use ($actor, $recipe, $stepId, $embed, $version): void {
            $recipe = Recipe::query()->whereKey($recipe->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('editVariation', $recipe);
            $this->assertVersion($recipe, $version);
            $step = $recipe->steps()->findOrFail($stepId);
            $source = $recipe->derivation->sourceRevision;
            // Original video URLs cannot be reattached through the new-media form.
            if (collect($source->media_snapshot ?? [])->contains(fn ($asset) => ($asset['url'] ?? null) === $embed)) {
                throw ValidationException::withMessages(['videoUrl' => __('variations.source_media_forbidden')]);
            }
            $media = Media::query()->create(['disk' => Media::REMOTE_DISK, 'media_type' => Media::TYPE_VIDEO, 'source' => Media::SOURCE_EMBED, 'url' => $embed, 'mime_type' => 'video/embed']);
            $step->media()->wherePivot('kind', 'video')->get()->each(fn ($old) => $step->media()->detach($old));
            $step->media()->attach($media, ['kind' => 'video', 'sort_order' => $step->media()->count()]);
            RecipeRevisionAudit::query()->create(['recipe_id' => $recipe->id, 'actor_id' => $actor->id, 'action' => 'variation_edited']);
            app(AuditLogger::class)->record('recipe_variation_video_added', ['recipe_id' => $recipe->id, 'media_id' => $media->id, 'rights_confirmed_by' => $actor->id]);
        });
    }
}
