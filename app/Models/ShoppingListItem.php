<?php

namespace App\Models;

use Database\Factories\ShoppingListItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShoppingListItem extends Model
{
    /** @use HasFactory<ShoppingListItemFactory> */
    use HasFactory;

    protected $hidden = ['source_requirements'];

    protected $attributes = ['version' => 1, 'is_manual' => false, 'is_optional' => false, 'is_stale' => false];

    protected $fillable = [
        'shopping_list_id', 'ingredient_id', 'custom_name', 'unit_id', 'generated_key', 'is_manual', 'is_optional', 'is_stale',
        'source_requirements', 'quantity', 'checked_at', 'checked_by', 'checked_base_quantity', 'extra_quantity', 'version',
    ];

    protected function casts(): array
    {
        return [
            'source_requirements' => 'array',
            'is_manual' => 'boolean',
            'is_optional' => 'boolean',
            'is_stale' => 'boolean',
            'quantity' => 'decimal:4',
            'checked_base_quantity' => 'decimal:4',
            'extra_quantity' => 'decimal:4',
            'checked_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function isChecked(): bool
    {
        return $this->checked_at !== null;
    }

    public function shoppingList(): BelongsTo
    {
        return $this->belongsTo(ShoppingList::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
