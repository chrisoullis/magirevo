<?php

namespace App\Models;

use App\Enums\IngredientCompositionStatus;
use App\Enums\IngredientOriginClassification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IngredientDietaryProfile extends Model
{
    protected $fillable = [
        'ingredient_id',
        'composition_status',
        'origin_classification',
        'evidence',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'composition_status' => IngredientCompositionStatus::class,
            'origin_classification' => IngredientOriginClassification::class,
            'evidence' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
