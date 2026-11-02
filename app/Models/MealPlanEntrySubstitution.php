<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MealPlanEntrySubstitution extends Model
{
    protected $fillable = [
        'meal_plan_entry_id',
        'recipe_id',
        'recipe_ingredient_id',
        'ingredient_substitution_id',
        'replacement_ingredient_id',
        'confirmed_by',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'confirmed_at' => 'immutable_datetime',
        ];
    }

    public function mealPlanEntry(): BelongsTo
    {
        return $this->belongsTo(MealPlanEntry::class);
    }

    public function recipeIngredient(): BelongsTo
    {
        return $this->belongsTo(RecipeIngredient::class);
    }

    public function ingredientSubstitution(): BelongsTo
    {
        return $this->belongsTo(IngredientSubstitution::class);
    }

    public function replacementIngredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'replacement_ingredient_id');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
