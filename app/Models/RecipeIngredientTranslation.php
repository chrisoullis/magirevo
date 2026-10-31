<?php

namespace App\Models;

use Database\Factories\RecipeIngredientTranslationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeIngredientTranslation extends Model
{
    /** @use HasFactory<RecipeIngredientTranslationFactory> */
    use HasFactory;

    protected $fillable = [
        'locale',
        'preparation_note',
        'quantity_text',
    ];

    public function recipeIngredient(): BelongsTo
    {
        return $this->belongsTo(RecipeIngredient::class);
    }
}
