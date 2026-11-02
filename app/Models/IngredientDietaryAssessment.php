<?php

namespace App\Models;

use App\Enums\DietaryCompatibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IngredientDietaryAssessment extends Model
{
    protected $fillable = [
        'ingredient_id',
        'tag_id',
        'outcome',
        'verification_method',
        'evidence',
        'reviewed_by',
        'reviewed_at',
        'rule_version',
        'review_notes',
    ];

    protected function casts(): array
    {
        return [
            'outcome' => DietaryCompatibility::class,
            'evidence' => 'array',
            'reviewed_at' => 'datetime',
            'rule_version' => 'integer',
        ];
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
