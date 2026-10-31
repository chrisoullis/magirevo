<?php

namespace App\Models;

use Astrotomic\Translatable\Translatable;
use Database\Factories\RecipeStepFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class RecipeStep extends Model
{
    /** @use HasFactory<RecipeStepFactory> */
    use HasFactory;

    use Translatable;

    protected $fillable = [
        'recipe_id',
        'step_number',
        'duration_minutes',
    ];

    public array $translatedAttributes = [
        'instruction',
    ];

    protected function casts(): array
    {
        return [
            'step_number' => 'integer',
            'duration_minutes' => 'integer',
        ];
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'recipe_step_media')
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }
}
