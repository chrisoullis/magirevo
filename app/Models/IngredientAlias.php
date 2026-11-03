<?php

namespace App\Models;

use Database\Factories\IngredientAliasFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IngredientAlias extends Model
{
    /** @use HasFactory<IngredientAliasFactory> */
    use HasFactory;

    protected $fillable = ['ingredient_id', 'locale', 'alias'];

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
