<?php

namespace App\Models;

use Database\Factories\IngredientGroupTranslationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IngredientGroupTranslation extends Model
{
    /** @use HasFactory<IngredientGroupTranslationFactory> */
    use HasFactory;

    protected $fillable = [
        'locale',
        'name',
    ];

    public function ingredientGroup(): BelongsTo
    {
        return $this->belongsTo(IngredientGroup::class);
    }
}
