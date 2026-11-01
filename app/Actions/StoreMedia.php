<?php

namespace App\Actions;

use App\Models\Media;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

/**
 * Stores an uploaded image and its bilingual alt text/caption as a Media row.
 * The optional callback runs in the same transaction so callers can attach the media atomically.
 */
class StoreMedia
{
    /**
     * @param  array{en: array{alt_text: string, caption?: ?string}, el: array{alt_text: string, caption?: ?string}}  $translations
     * @param  (Closure(Media): void)|null  $within
     */
    public function handle(UploadedFile $file, array $translations, string $directory = 'recipe-media', ?Closure $within = null, string $disk = 'public'): Media
    {
        Validator::make([
            'file' => $file,
            'translations' => $translations,
        ], [
            'file' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:10240',
                Rule::dimensions()->maxWidth(6000)->maxHeight(6000),
            ],
            'translations' => ['required', 'array:en,el'],
            'translations.en' => ['required', 'array:alt_text,caption'],
            'translations.en.alt_text' => ['required', 'string', 'max:255'],
            'translations.en.caption' => ['nullable', 'string'],
            'translations.el' => ['required', 'array:alt_text,caption'],
            'translations.el.alt_text' => ['required', 'string', 'max:255'],
            'translations.el.caption' => ['nullable', 'string'],
        ])->validate();

        $dimensions = getimagesize($file->getRealPath());

        if ($dimensions === false) {
            throw new RuntimeException('Unable to read the uploaded image dimensions.');
        }

        $mimeType = $file->getMimeType();
        $fileSize = $file->getSize();

        if ($mimeType === null || $fileSize === false) {
            throw new RuntimeException('Unable to read the uploaded image metadata.');
        }

        $path = $disk === 'public' ? $file->storePublicly($directory, $disk) : $file->store($directory, $disk);

        if (! is_string($path)) {
            throw new RuntimeException('Unable to store the uploaded image.');
        }

        try {
            return DB::transaction(function () use ($translations, $dimensions, $path, $mimeType, $fileSize, $within, $disk): Media {
                $media = Media::query()->create([
                    'disk' => $disk,
                    'file_path' => $path,
                    'mime_type' => $mimeType,
                    'file_size' => $fileSize,
                    'width' => $dimensions[0],
                    'height' => $dimensions[1],
                ]);

                $media->translations()->createMany([
                    ['locale' => 'en', ...$translations['en']],
                    ['locale' => 'el', ...$translations['el']],
                ]);

                if ($within !== null) {
                    $within($media);
                }

                return $media;
            });
        } catch (Throwable $exception) {
            if (! Storage::disk($disk)->delete($path)) {
                throw new RuntimeException('Unable to remove the uploaded image after media persistence failed.', previous: $exception);
            }

            throw $exception;
        }
    }
}
