<?php

namespace App\Actions;

use App\Models\Media;
use App\Models\Recipe;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

class StoreRecipeMedia
{
    /**
     * @param  array{en: array{alt_text: string, caption?: ?string}, el: array{alt_text: string, caption?: ?string}}  $translations
     */
    public function handle(Recipe $recipe, UploadedFile $file, array $translations, string $role = 'gallery'): Media
    {
        Validator::make([
            'file' => $file,
            'translations' => $translations,
            'role' => $role,
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
            'role' => ['required', Rule::in(['cover', 'gallery'])],
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

        $path = $file->storePublicly('recipe-media', 'public');

        if (! is_string($path)) {
            throw new RuntimeException('Unable to store the uploaded recipe image.');
        }

        try {
            return DB::transaction(function () use ($recipe, $translations, $role, $dimensions, $path, $mimeType, $fileSize): Media {
                $media = Media::query()->create([
                    'disk' => 'public',
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

                $recipe->media()->attach($media, [
                    'role' => $role,
                    'sort_order' => $recipe->media()->count(),
                ]);

                return $media;
            });
        } catch (Throwable $exception) {
            if (! Storage::disk('public')->delete($path)) {
                throw new RuntimeException('Unable to remove the uploaded image after media persistence failed.', previous: $exception);
            }

            throw $exception;
        }
    }
}
