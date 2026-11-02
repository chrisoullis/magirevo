<?php

namespace App\Models;

use Database\Factories\UserRecipeIngredientChangeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserRecipeIngredientChange extends Model
{
    /** @use HasFactory<UserRecipeIngredientChangeFactory> */
    use HasFactory;

    protected $fillable = [
        'recipe_id',
        'recipe_ingredient_id',
        'alternative_ingredient_id',
        'note',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function recipeIngredient(): BelongsTo
    {
        return $this->belongsTo(RecipeIngredient::class);
    }

    public function alternativeIngredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'alternative_ingredient_id');
    }
}
