<?php

namespace App\Actions;

use App\Models\Media;
use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Support\TrustedVideoUrl;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class SyncRecipeStepMedia
{
    public function __construct(
        private StoreRecipeStepImage $storeImage,
        private DeleteMediaIfUnused $deleteMediaIfUnused,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $images
     * @param  array{en: array{alt_text: ?string, caption: ?string}, el: array{alt_text: ?string, caption: ?string}}  $videoTranslations
     * @return list<array{disk: string, path: string}>
     */
    public function handle(Recipe $recipe, RecipeStep $step, array $images, ?string $videoUrl, array $videoTranslations): array
    {
        Gate::authorize('update', $recipe);
        if ((int) $step->recipe_id !== (int) $recipe->getKey()) {
            throw ValidationException::withMessages(['steps_data' => __('site.step_media_invalid')]);
        }

        $normalizedImages = [];

        foreach ($images as $image) {
            if (! is_array($image)) {
                throw ValidationException::withMessages(['steps_data' => __('site.step_media_invalid')]);
            }

            $upload = $image['upload'] ?? null;

            if (is_array($upload)) {
                if (count($upload) > 1) {
                    throw ValidationException::withMessages(['steps_data' => __('site.step_media_invalid')]);
                }

                $upload = reset($upload) ?: null;
            }

            $normalizedImages[] = [...$image, 'upload' => $upload];
        }

        $images = $normalizedImages;
        $this->validate($images, $videoUrl, $videoTranslations);

        $storedFiles = [];

        try {
            DB::transaction(function () use ($recipe, $step, $images, $videoUrl, $videoTranslations, &$storedFiles): void {
                $this->syncImages($recipe, $step, array_values($images), $storedFiles);
                $this->syncVideo($step, $videoUrl, $videoTranslations, count($images));
            });
        } catch (Throwable $exception) {
            foreach ($storedFiles as $storedFile) {
                if (! Storage::disk($storedFile['disk'])->delete($storedFile['path'])) {
                    throw new RuntimeException('Unable to remove a step image after recipe content persistence failed.', previous: $exception);
                }
            }

            throw $exception;
        }

        return $storedFiles;
    }

    /**
     * @param  list<array<string, mixed>>  $images
     * @param  array{en: array{alt_text: ?string, caption: ?string}, el: array{alt_text: ?string, caption: ?string}}  $videoTranslations
     */
    private function validate(array $images, ?string $videoUrl, array $videoTranslations): void
    {
        Validator::make([
            'images' => $images,
            'video_url' => $videoUrl,
            'video_translations' => $videoTranslations,
        ], [
            'images' => ['array', 'max:3'],
            'images.*' => ['array:media_id,upload,alt_text_en,caption_en,alt_text_el,caption_el'],
            'images.*.media_id' => ['nullable', 'integer', 'distinct:strict'],
            'images.*.upload' => [
                'nullable',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:10240',
                Rule::dimensions()->maxWidth(6000)->maxHeight(6000),
            ],
            'images.*.alt_text_en' => ['required', 'string', 'max:255'],
            'images.*.caption_en' => ['nullable', 'string', 'max:1000'],
            'images.*.alt_text_el' => ['required', 'string', 'max:255'],
            'images.*.caption_el' => ['nullable', 'string', 'max:1000'],
            'video_url' => [
                'nullable',
                'string',
                'max:2048',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (filled($value) && (! is_string($value) || TrustedVideoUrl::embedUrl($value) === null)) {
                        $fail(__('site.video_url_invalid'));
                    }
                },
            ],
            'video_translations' => ['required', 'array:en,el'],
            'video_translations.en' => ['required', 'array:alt_text,caption'],
            'video_translations.en.alt_text' => ['nullable', 'string', 'max:255'],
            'video_translations.en.caption' => ['nullable', 'string', 'max:1000'],
            'video_translations.el' => ['required', 'array:alt_text,caption'],
            'video_translations.el.alt_text' => ['nullable', 'string', 'max:255'],
            'video_translations.el.caption' => ['nullable', 'string', 'max:1000'],
        ])->validate();
    }

    /**
     * @param  list<array<string, mixed>>  $images
     * @param  list<array{disk: string, path: string}>  $storedFiles
     */
    private function syncImages(Recipe $recipe, RecipeStep $step, array $images, array &$storedFiles): void
    {
        $existingImages = $step->media()
            ->wherePivot('kind', 'image')
            ->where('media_type', Media::TYPE_IMAGE)
            ->get()
            ->keyBy('id');
        $keptIds = [];

        foreach ($images as $sortOrder => $image) {
            $mediaId = $image['media_id'] ?? null;
            $upload = $image['upload'] ?? null;
            $translations = [
                'en' => ['alt_text' => $image['alt_text_en'], 'caption' => $image['caption_en'] ?? null],
                'el' => ['alt_text' => $image['alt_text_el'], 'caption' => $image['caption_el'] ?? null],
            ];

            if (filled($mediaId)) {
                $mediaId = (int) $mediaId;
                $media = $existingImages->get($mediaId);

                if ($media === null || $upload !== null) {
                    throw ValidationException::withMessages(['steps_data' => __('site.step_media_invalid')]);
                }

                $this->updateTranslations($media, $translations);
                $step->media()->updateExistingPivot($mediaId, ['sort_order' => $sortOrder]);
                $keptIds[] = $mediaId;

                continue;
            }

            if (! $upload instanceof UploadedFile) {
                throw ValidationException::withMessages(['steps_data' => __('site.step_media_upload_required')]);
            }

            $media = $this->storeImage->handle($recipe, $step, $upload, $translations, $sortOrder);
            $storedFiles[] = ['disk' => $media->disk, 'path' => (string) $media->file_path];
            $keptIds[] = (int) $media->getKey();
        }

        foreach ($existingImages as $mediaId => $media) {
            if (! in_array((int) $mediaId, $keptIds, true)) {
                $step->media()->detach($mediaId);
                $this->deleteMediaIfUnused->handle($media);
            }
        }
    }

    /**
     * @param  array{en: array{alt_text: ?string, caption: ?string}, el: array{alt_text: ?string, caption: ?string}}  $translations
     */
    private function syncVideo(RecipeStep $step, ?string $videoUrl, array $translations, int $sortOrder): void
    {
        $currentVideo = $step->media()
            ->wherePivot('kind', 'video')
            ->where('media_type', Media::TYPE_VIDEO)
            ->first();

        if (blank($videoUrl)) {
            if ($currentVideo !== null) {
                $step->media()->detach($currentVideo);
                $this->deleteMediaIfUnused->handle($currentVideo);
            }

            return;
        }

        $embedUrl = TrustedVideoUrl::embedUrl($videoUrl);

        if ($embedUrl === null) {
            throw ValidationException::withMessages(['video_url' => __('site.video_url_invalid')]);
        }

        if ($currentVideo !== null && $currentVideo->url === $embedUrl) {
            $this->updateTranslations($currentVideo, $this->videoTranslations($translations, $step));

            return;
        }

        if ($currentVideo !== null) {
            $step->media()->detach($currentVideo);
            $this->deleteMediaIfUnused->handle($currentVideo);
        }

        $media = Media::query()->create([
            'disk' => Media::REMOTE_DISK,
            'media_type' => Media::TYPE_VIDEO,
            'source' => Media::SOURCE_EMBED,
            'url' => $embedUrl,
            'mime_type' => 'video/embed',
        ]);

        $this->updateTranslations($media, $this->videoTranslations($translations, $step));
        $step->media()->attach($media, ['kind' => 'video', 'sort_order' => $sortOrder]);
    }

    /**
     * @param  array{en: array{alt_text: ?string, caption: ?string}, el: array{alt_text: ?string, caption: ?string}}  $translations
     */
    private function videoTranslations(array $translations, RecipeStep $step): array
    {
        return [
            'en' => [
                'alt_text' => $translations['en']['alt_text'] ?: trans('site.step_video_alt', ['number' => $step->step_number], 'en'),
                'caption' => $translations['en']['caption'],
            ],
            'el' => [
                'alt_text' => $translations['el']['alt_text'] ?: trans('site.step_video_alt', ['number' => $step->step_number], 'el'),
                'caption' => $translations['el']['caption'],
            ],
        ];
    }

    /**
     * @param  array{en: array{alt_text: string, caption: ?string}, el: array{alt_text: string, caption: ?string}}  $translations
     */
    private function updateTranslations(Media $media, array $translations): void
    {
        foreach (['en', 'el'] as $locale) {
            $media->translations()->updateOrCreate(['locale' => $locale], $translations[$locale]);
        }
    }
}
