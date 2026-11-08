<?php

namespace App\Models;

use Astrotomic\Translatable\Translatable;
use Database\Factories\RecipeStepFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Validation\ValidationException;

class RecipeStep extends Model
{
    /** @use HasFactory<RecipeStepFactory> */
    use HasFactory;

    use Translatable;

    protected $fillable = [
        'recipe_id',
        'recipe_step_group_id',
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

    protected static function booted(): void
    {
        static::saving(function (RecipeStep $step): void {
            if ($step->recipe_step_group_id !== null &&
                ($step->isDirty('recipe_step_group_id') || $step->isDirty('recipe_id')) &&
                ! RecipeStepGroup::query()->whereKey($step->recipe_step_group_id)->where('recipe_id', $step->recipe_id)->exists()) {
                throw ValidationException::withMessages([
                    'recipe_step_group_id' => __('cooking.group_recipe_mismatch'),
                ]);
            }
        });
    }

    public function stepGroup(): BelongsTo
    {
        return $this->belongsTo(RecipeStepGroup::class, 'recipe_step_group_id');
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'recipe_step_media')
            ->withPivot(['kind', 'sort_order'])
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }
}
