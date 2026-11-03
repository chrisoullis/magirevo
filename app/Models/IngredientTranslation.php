<?php

namespace App\Models;

use Database\Factories\IngredientTranslationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IngredientTranslation extends Model
{
    /** @use HasFactory<IngredientTranslationFactory> */
    use HasFactory;

    protected $fillable = [
        'locale',
        'name',
        'plural_name',
        'description',
        'slug',
        'culinary_uses',
        'preparation_tips',
        'storage_tips',
    ];

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
