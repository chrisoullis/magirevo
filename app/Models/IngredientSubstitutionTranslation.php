<?php

namespace App\Models;

use Database\Factories\IngredientSubstitutionTranslationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IngredientSubstitutionTranslation extends Model
{
    /** @use HasFactory<IngredientSubstitutionTranslationFactory> */
    use HasFactory;

    protected $fillable = [
        'locale',
        'explanation',
    ];

    public function ingredientSubstitution(): BelongsTo
    {
        return $this->belongsTo(IngredientSubstitution::class);
    }
}
