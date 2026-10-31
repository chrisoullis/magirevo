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
    ];

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
