<?php

namespace App\Services;

use App\Actions\DeleteMediaIfUnused;
use App\Actions\StoreMedia;
use App\Models\Ingredient;
use App\Models\IngredientMedia;
use App\Models\Media;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class IngredientImageService
{
    public function __construct(private StoreMedia $store, private RecipeStepImageOptimizer $optimizer, private DeleteMediaIfUnused $delete) {}

    /** @param array<string, mixed> $data */
    public function upload(User $actor, Ingredient $ingredient, UploadedFile $file, array $data): IngredientMedia
    {
        Gate::forUser($actor)->authorize('update', $ingredient);
        $this->validate($data);
        Validator::make(['file' => $file], ['file' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240', Rule::dimensions()->maxWidth(6000)->maxHeight(6000)]])->validate();
        $paths = [];
        $stored = [];

        try {
            $paths[] = $this->optimizer->optimize($file);
            $paths[] = $this->optimizer->optimize($file, 480);

            return DB::transaction(function () use ($actor, $ingredient, $data, $paths, &$stored): IngredientMedia {
                Ingredient::query()->whereKey($ingredient->id)->lockForUpdate()->firstOrFail();
                $translations = $this->translations($data);
                $stored[] = $image = $this->store->handle(new UploadedFile($paths[0], 'ingredient.webp', 'image/webp', UPLOAD_ERR_OK, true), $translations, 'ingredient-media', disk: 'local');
                $stored[] = $thumbnail = $this->store->handle(new UploadedFile($paths[1], 'thumbnail.webp', 'image/webp', UPLOAD_ERR_OK, true), $translations, 'ingredient-thumbnails', disk: 'local');

                if ($data['role'] === 'primary') {
                    $ingredient->imageAssignments()->where('role', 'primary')->update(['role' => 'gallery']);
                }

                return $ingredient->imageAssignments()->create([
                    'media_id' => $image->id, 'thumbnail_media_id' => $thumbnail->id,
                    'role' => $data['role'], 'sort_order' => ((int) $ingredient->imageAssignments()->max('sort_order')) + 10,
                    'rights_basis' => $data['rights_basis'], 'rights_reference' => $data['rights_reference'],
                    'rights_confirmed_by' => $actor->id, 'rights_confirmed_at' => now(),
                ]);
            });
        } catch (Throwable $exception) {
            foreach ($stored as $media) {
                Storage::disk($media->disk)->delete($media->file_path);
            }
            throw $exception;
        } finally {
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    /** @param array<string, mixed> $data */
    public function assign(User $actor, Ingredient $ingredient, Media $source, array $data): IngredientMedia
    {
        Gate::forUser($actor)->authorize('update', $ingredient);
        $this->validate($data);
        $eligible = $source->isLocal() && $source->disk === 'local'
            && $source->media_type === Media::TYPE_IMAGE
            && str_starts_with((string) $source->file_path, 'ingredient-media/')
            && $source->ingredientImages()->with('ingredient')->get()
                ->contains(fn (IngredientMedia $image): bool => Gate::forUser($actor)->allows('view', $image->ingredient))
            && ! $source->recipes()->exists() && ! $source->recipeSteps()->exists()
            && ! $source->categories()->exists() && ! $source->isRetainedByRevision();

        if (! $eligible || ! Storage::disk($source->disk)->exists($source->file_path)) {
            throw ValidationException::withMessages(['media_id' => 'Select a rights-cleared ingredient-library image. Recipe, revision and external images cannot be assigned.']);
        }

        $path = tempnam(sys_get_temp_dir(), 'ingredient-assignment-');
        if ($path === false) {
            throw new RuntimeException('Unable to prepare the ingredient image.');
        }

        try {
            $input = Storage::disk($source->disk)->readStream($source->file_path);
            $output = fopen($path, 'wb');
            if (! is_resource($input) || ! is_resource($output)) {
                if (is_resource($input)) {
                    fclose($input);
                }
                if (is_resource($output)) {
                    fclose($output);
                }
                throw new RuntimeException('Unable to copy the ingredient image.');
            }
            try {
                if (stream_copy_to_stream($input, $output) === false) {
                    throw new RuntimeException('Unable to copy the ingredient image.');
                }
            } finally {
                fclose($input);
                fclose($output);
            }

            return $this->upload($actor, $ingredient, new UploadedFile($path, 'ingredient.webp', 'image/webp', UPLOAD_ERR_OK, true), $data);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function promote(User $actor, Ingredient $ingredient, int $assignmentId): void
    {
        Gate::forUser($actor)->authorize('update', $ingredient);
        DB::transaction(function () use ($ingredient, $assignmentId): void {
            Ingredient::query()->whereKey($ingredient->id)->lockForUpdate()->firstOrFail();
            $image = $ingredient->imageAssignments()->findOrFail($assignmentId);
            $ingredient->imageAssignments()->where('role', 'primary')->update(['role' => 'gallery']);
            $image->update(['role' => 'primary']);
        });
    }

    public function remove(User $actor, Ingredient $ingredient, int $assignmentId): void
    {
        Gate::forUser($actor)->authorize('update', $ingredient);
        DB::transaction(function () use ($ingredient, $assignmentId): void {
            Ingredient::query()->whereKey($ingredient->id)->lockForUpdate()->firstOrFail();
            $image = $ingredient->imageAssignments()->with(['media', 'thumbnail'])->findOrFail($assignmentId);
            $media = $image->media;
            $thumbnail = $image->thumbnail;
            $image->delete();
            $this->delete->handle($media);
            if ($thumbnail !== null) {
                $this->delete->handle($thumbnail);
            }
        });
    }

    /** @param array<string, mixed> $data */
    public function updateText(User $actor, Ingredient $ingredient, int $assignmentId, array $data): void
    {
        Gate::forUser($actor)->authorize('update', $ingredient);
        Validator::make($data, $this->textRules())->validate();
        DB::transaction(function () use ($ingredient, $assignmentId, $data): void {
            $image = $ingredient->imageAssignments()->with(['media', 'thumbnail'])->findOrFail($assignmentId);
            foreach ([$image->media, $image->thumbnail] as $media) {
                if ($media === null) {
                    continue;
                }
                if ($media->ingredientImages()->count() + $media->ingredientThumbnails()->count() > 1 || $media->recipes()->exists() || $media->recipeSteps()->exists() || $media->isRetainedByRevision() || $media->categories()->exists()) {
                    throw ValidationException::withMessages(['alt_text_en' => 'This image is shared. Assign a dedicated copy before editing its text.']);
                }
                $media->fill($this->translations($data))->save();
            }
        });
    }

    /** @param array<string, mixed> $data */
    private function validate(array $data): void
    {
        Validator::make($data, $this->textRules() + [
            'role' => ['required', Rule::in(['primary', 'gallery'])],
            'rights_basis' => ['required', Rule::in(['own_work', 'licensed', 'permission', 'public_domain'])],
            'rights_reference' => ['required', 'string', 'max:2000'],
        ])->validate();
    }

    /** @return array<string, array<int, string>> */
    private function textRules(): array
    {
        return ['alt_text_en' => ['required', 'string', 'max:255'], 'alt_text_el' => ['required', 'string', 'max:255'], 'caption_en' => ['nullable', 'string', 'max:1000'], 'caption_el' => ['nullable', 'string', 'max:1000']];
    }

    /** @param array<string, mixed> $data
     * @return array<string, array<string, mixed>>
     */
    private function translations(array $data): array
    {
        return ['en' => ['alt_text' => $data['alt_text_en'], 'caption' => $data['caption_en'] ?? null], 'el' => ['alt_text' => $data['alt_text_el'], 'caption' => $data['caption_el'] ?? null]];
    }
}
