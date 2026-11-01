<?php

namespace App\Actions;

use App\Models\Category;
use App\Models\Media;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class SetCategoryImage
{
    public function __construct(
        private StoreMedia $storeMedia,
        private CreateRemoteMedia $createRemoteMedia,
        private DeleteMediaIfUnused $deleteMediaIfUnused,
    ) {}

    /**
     * Replaces the category image with a new upload; the previous media is deleted only if nothing else uses it.
     *
     * @param  array{en: array{alt_text: string, caption?: ?string}, el: array{alt_text: string, caption?: ?string}}  $translations
     */
    public function upload(Category $category, UploadedFile $file, array $translations): Media
    {
        return $this->swap($category, fn (Closure $assign): Media => $this->storeMedia->handle($file, $translations, 'category-media', $assign));
    }

    /**
     * @param  array{en: array{alt_text: string, caption?: ?string}, el: array{alt_text: string, caption?: ?string}}  $translations
     */
    public function remote(Category $category, string $url, array $translations, ?string $credit = null, ?string $creditUrl = null): Media
    {
        return $this->swap($category, fn (Closure $assign): Media => $this->createRemoteMedia->handle($url, $translations, $credit, $creditUrl, $assign));
    }

    /**
     * Keeps the image but updates its bilingual alt text and captions.
     *
     * @param  array{en: array{alt_text: string, caption?: ?string}, el: array{alt_text: string, caption?: ?string}}  $translations
     */
    public function updateText(Category $category, array $translations): void
    {
        $category->media?->fill($translations)->save();
    }

    public function remove(Category $category): void
    {
        DB::transaction(function () use ($category): void {
            $previous = $category->media;

            $category->forceFill(['media_id' => null])->save();
            $category->unsetRelation('media');

            if ($previous !== null) {
                $this->deleteMediaIfUnused->handle($previous);
            }
        });
    }

    /**
     * @param  Closure(Closure): Media  $create
     */
    private function swap(Category $category, Closure $create): Media
    {
        return DB::transaction(function () use ($category, $create): Media {
            $previous = $category->media;

            $media = $create(function (Media $media) use ($category): void {
                $category->forceFill(['media_id' => $media->getKey()])->save();
            });

            $category->setRelation('media', $media);

            if ($previous !== null) {
                $this->deleteMediaIfUnused->handle($previous);
            }

            return $media;
        });
    }
}
