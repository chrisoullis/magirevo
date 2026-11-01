<?php

namespace App\Models;

use App\Enums\MealType;
use Database\Factories\MealPlanTemplateEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MealPlanTemplateEntry extends Model
{
    /** @use HasFactory<MealPlanTemplateEntryFactory> */
    use HasFactory;

    protected $fillable = ['meal_plan_template_id', 'recipe_id', 'day_of_week', 'meal_type', 'servings', 'notes'];

    protected function casts(): array
    {
        return ['day_of_week' => 'integer', 'meal_type' => MealType::class, 'servings' => 'integer'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MealPlanTemplate::class, 'meal_plan_template_id');
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }
}
