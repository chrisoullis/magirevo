<?php

namespace App\Models;

use Database\Factories\RecipeTranslationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class RecipeTranslation extends Model
{
    /** @use HasFactory<RecipeTranslationFactory> */
    use HasFactory;

    use HasSlug;

    protected $fillable = [
        'locale',
        'title',
        'slug',
        'summary',
        'introduction',
        'seo_title',
        'seo_description',
    ];

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('title')
            ->saveSlugsTo('slug')
            ->usingLanguage($this->locale ?? 'en')
            ->slugsShouldBeNoLongerThan(255)
            ->preventOverwrite()
            ->doNotGenerateSlugsOnUpdate()
            ->extraScope(fn ($query) => $query->where('locale', $this->locale));
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }
}
