<?php

namespace App\Models;

use Astrotomic\Translatable\Translatable;
use Database\Factories\IngredientSubstitutionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class IngredientSubstitution extends Model
{
    /** @use HasFactory<IngredientSubstitutionFactory> */
    use HasFactory;

    use Translatable;

    protected $fillable = [
        'ingredient_id',
        'replacement_ingredient_id',
        'recipe_id',
        'status',
    ];

    public array $translatedAttributes = [
        'explanation',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $substitution): void {
            if ($substitution->recipe_id !== null
                && ! RecipeIngredient::query()
                    ->where('recipe_id', $substitution->recipe_id)
                    ->where('ingredient_id', $substitution->ingredient_id)
                    ->exists()) {
                throw ValidationException::withMessages([
                    'recipe_id' => __('site.substitution_recipe_ingredient_mismatch'),
                ]);
            }
        });
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function replacementIngredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'replacement_ingredient_id');
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    public function scopeApplicableToRecipe(Builder $query, int $recipeId): Builder
    {
        return $query->where(fn (Builder $applicable): Builder => $applicable
            ->whereNull('recipe_id')
            ->orWhere('recipe_id', $recipeId));
    }
}
