<?php

namespace App\Filament\Resources\Categories\Pages;

use App\Actions\SetCategoryImage;
use App\Models\Category;
use Illuminate\Http\UploadedFile;

/**
 * Keeps the transient "image" form state out of the Category attributes and applies it through SetCategoryImage.
 */
trait HandlesCategoryImage
{
    /**
     * @var array<string, mixed>
     */
    protected array $imageData = [];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function fillImageFormData(array $data, Category $category): array
    {
        $media = $category->media;

        $data['image'] = [
            'alt_text_en' => $media?->getTranslation('en', false)?->alt_text,
            'caption_en' => $media?->getTranslation('en', false)?->caption,
            'alt_text_el' => $media?->getTranslation('el', false)?->alt_text,
            'caption_el' => $media?->getTranslation('el', false)?->caption,
            'url' => $media?->isRemote() ? $media->url : null,
            'credit' => $media?->credit,
            'credit_url' => $media?->credit_url,
        ];

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function extractImageData(array $data): array
    {
        $this->imageData = $data['image'] ?? [];
        unset($data['image']);

        return $data;
    }

    protected function applyImageData(Category $category): void
    {
        $image = $this->imageData;
        $this->imageData = [];

        $setImage = app(SetCategoryImage::class);

        if (! empty($image['remove']) && blank($image['file'] ?? null) && blank($image['url'] ?? null)) {
            $setImage->remove($category);

            return;
        }

        $translations = [
            'en' => ['alt_text' => (string) ($image['alt_text_en'] ?? ''), 'caption' => $image['caption_en'] ?? null],
            'el' => ['alt_text' => (string) ($image['alt_text_el'] ?? ''), 'caption' => $image['caption_el'] ?? null],
        ];

        $file = $image['file'] ?? null;
        $file = is_array($file) ? (reset($file) ?: null) : $file;

        if ($file instanceof UploadedFile) {
            $setImage->upload($category, $file, $translations);
        } elseif (filled($image['url'] ?? null) && $category->media?->url !== $image['url']) {
            $setImage->remote($category, $image['url'], $translations, $image['credit'] ?? null, $image['credit_url'] ?? null);
        } elseif ($category->media !== null && filled($translations['en']['alt_text']) && filled($translations['el']['alt_text'])) {
            $setImage->updateText($category, $translations);
        }
    }
}
