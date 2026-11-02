<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IngredientComponent extends Model
{
    protected $fillable = [
        'ingredient_id',
        'component_ingredient_id',
        'unresolved_component',
        'evidence',
    ];

    protected function casts(): array
    {
        return ['evidence' => 'array'];
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function componentIngredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'component_ingredient_id');
    }
}
