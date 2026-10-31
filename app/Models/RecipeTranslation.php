<?php

namespace App\Models;

use Database\Factories\RecipeTranslationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeTranslation extends Model
{
    /** @use HasFactory<RecipeTranslationFactory> */
    use HasFactory;

    protected $fillable = [
        'locale',
        'title',
        'slug',
        'summary',
        'introduction',
        'seo_title',
        'seo_description',
    ];

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }
}
