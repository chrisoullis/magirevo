<?php

namespace App\Models;

use App\Enums\MealType;
use Database\Factories\MealPlanEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MealPlanEntry extends Model
{
    /** @use HasFactory<MealPlanEntryFactory> */
    use HasFactory;

    protected $attributes = ['version' => 1];

    protected $fillable = ['meal_plan_id', 'recipe_id', 'planned_on', 'meal_type', 'servings', 'notes', 'added_by', 'batch_cook_id', 'consumed_at', 'version'];

    protected function casts(): array
    {
        return [
            'planned_on' => 'immutable_date',
            'meal_type' => MealType::class,
            'servings' => 'integer',
            'consumed_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function mealPlan(): BelongsTo
    {
        return $this->belongsTo(MealPlan::class);
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    public function isLeftover(): bool
    {
        return $this->batch_cook_id !== null;
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(BatchCook::class, 'batch_cook_id');
    }

    public function confirmedSubstitutions(): HasMany
    {
        return $this->hasMany(MealPlanEntrySubstitution::class);
    }
}
