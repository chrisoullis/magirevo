<?php

namespace App\Models;

use Astrotomic\Translatable\Translatable;
use Database\Factories\RecipeIngredientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecipeIngredient extends Model
{
    /** @use HasFactory<RecipeIngredientFactory> */
    use HasFactory;

    use Translatable;

    protected $fillable = [
        'recipe_id',
        'ingredient_id',
        'ingredient_group_id',
        'quantity',
        'quantity_max',
        'unit_id',
        'scaling_mode',
        'is_optional',
        'sort_order',
    ];

    public array $translatedAttributes = [
        'preparation_note',
        'quantity_text',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'quantity_max' => 'decimal:4',
            'is_optional' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function ingredientGroup(): BelongsTo
    {
        return $this->belongsTo(IngredientGroup::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function personalChanges(): HasMany
    {
        return $this->hasMany(UserRecipeIngredientChange::class);
    }
}
