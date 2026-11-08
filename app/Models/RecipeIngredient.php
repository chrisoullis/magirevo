<?php

namespace App\Models;

use Astrotomic\Translatable\Translatable;
use Database\Factories\RecipeIngredientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class RecipeIngredient extends Model
{
    /** @use HasFactory<RecipeIngredientFactory> */
    use HasFactory;

    use Translatable;

    protected $attributes = ['line_type' => 'ingredient'];

    protected static function booted(): void
    {
        static::creating(function (self $line): void {
            $line->line_uuid ??= (string) Str::uuid();
        });
    }

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
        'line_uuid',
        'line_type',
        'component_recipe_id',
        'component_recipe_revision_id',
        'quantity_basis',
        'shopping_mode',
        'prepared_ingredient_id',
        'prepared_quantity_per_batch',
        'prepared_unit_id',
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
            'prepared_quantity_per_batch' => 'decimal:4',
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

    public function componentRecipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class, 'component_recipe_id')->withTrashed();
    }

    public function componentRevision(): BelongsTo
    {
        return $this->belongsTo(RecipeRevision::class, 'component_recipe_revision_id');
    }

    public function preparedIngredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'prepared_ingredient_id');
    }

    public function preparedUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'prepared_unit_id');
    }

    public function isComponent(): bool
    {
        return $this->line_type === 'recipe';
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
