<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IngredientCatalogGroupTranslation extends Model
{
    protected $fillable = ['locale', 'name', 'description'];

    public function ingredientCatalogGroup(): BelongsTo
    {
        return $this->belongsTo(IngredientCatalogGroup::class);
    }
}
