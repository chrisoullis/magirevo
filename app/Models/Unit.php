<?php

namespace App\Models;

use Astrotomic\Translatable\Translatable;
use Database\Factories\UnitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Conversion factors are relative to grams, milliliters, or pieces by unit type.
 */
class Unit extends Model
{
    /** @use HasFactory<UnitFactory> */
    use HasFactory;

    use Translatable;

    protected $fillable = [
        'code',
        'unit_type',
        'base_conversion',
        'allow_fraction',
        'is_scalable',
        'sort_order',
    ];

    public array $translatedAttributes = [
        'name',
        'abbreviation',
    ];

    protected function casts(): array
    {
        return [
            'base_conversion' => 'decimal:8',
            'allow_fraction' => 'boolean',
            'is_scalable' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(Ingredient::class, 'default_unit_id');
    }

    public function recipeIngredients(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class);
    }

    public function isInUse(): bool
    {
        return $this->recipeIngredients()->exists() || $this->ingredients()->exists();
    }
}
