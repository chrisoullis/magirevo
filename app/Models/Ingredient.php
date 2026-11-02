<?php

namespace App\Models;

use Astrotomic\Translatable\Translatable;
use Database\Factories\IngredientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ingredient extends Model
{
    /** @use HasFactory<IngredientFactory> */
    use HasFactory;

    use Translatable;

    protected $fillable = [
        'code',
        'default_unit_id',
        'is_active',
    ];

    public array $translatedAttributes = [
        'name',
        'plural_name',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function defaultUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'default_unit_id');
    }

    public function recipeIngredients(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class);
    }

    public function substitutions(): HasMany
    {
        return $this->hasMany(IngredientSubstitution::class);
    }

    public function substitutionSuggestions(): HasMany
    {
        return $this->hasMany(IngredientSubstitution::class, 'replacement_ingredient_id');
    }

    public function personalRecipeIngredientChanges(): HasMany
    {
        return $this->hasMany(UserRecipeIngredientChange::class, 'alternative_ingredient_id');
    }

    public function isInUse(): bool
    {
        return $this->recipeIngredients()->exists();
    }
}
