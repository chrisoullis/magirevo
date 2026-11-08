<?php

namespace App\Models;

use Database\Factories\RecipeYieldFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeYield extends Model
{
    /** @use HasFactory<RecipeYieldFactory> */
    use HasFactory;

    protected $fillable = ['recipe_id', 'basis', 'quantity', 'unit_id', 'evidence', 'verified_by', 'verified_at'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'evidence' => 'array', 'verified_at' => 'datetime'];
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
