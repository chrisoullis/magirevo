<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;

/**
 * Set-based versions of the completeness rules in RecipePublishingService::assertPublishable(), so the
 * dashboard can count problem recipes with aggregate queries instead of validating each recipe in PHP.
 * Keep the two in step; RecipeQualityMonitorTest compares them.
 */
class RecipeQualityQuery
{
    public function __construct(private Builder $recipes) {}

    public function missingTranslations(): Builder
    {
        return (clone $this->recipes)->where(function (Builder $recipes): void {
            foreach (config('translatable.locales') as $locale) {
                $recipes->orWhereDoesntHave('translations', fn (Builder $translation) => $translation
                    ->where('locale', $locale)
                    ->where('title', '<>', '')->whereNotNull('title')
                    ->where('slug', '<>', '')->whereNotNull('slug')
                    ->where('summary', '<>', '')->whereNotNull('summary'));
            }
        });
    }

    public function missingCover(): Builder
    {
        return (clone $this->recipes)->whereDoesntHave('media', fn (Builder $media) => $media->where('recipe_media.role', 'cover'));
    }

    public function missingIngredients(): Builder
    {
        return (clone $this->recipes)->whereDoesntHave('recipeIngredients');
    }

    public function missingSteps(): Builder
    {
        return (clone $this->recipes)->whereDoesntHave('steps');
    }

    public function missingSeo(): Builder
    {
        return (clone $this->recipes)->where(function (Builder $recipes): void {
            foreach (config('translatable.locales') as $locale) {
                $recipes->orWhereDoesntHave('translations', fn (Builder $translation) => $translation
                    ->where('locale', $locale)
                    ->where('seo_title', '<>', '')->whereNotNull('seo_title')
                    ->where('seo_description', '<>', '')->whereNotNull('seo_description'));
            }
        });
    }
}
