<?php

namespace Database\Factories;

use App\Enums\MealType;
use App\Models\MealPlanTemplate;
use App\Models\MealPlanTemplateEntry;
use App\Models\Recipe;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MealPlanTemplateEntry>
 */
class MealPlanTemplateEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'meal_plan_template_id' => MealPlanTemplate::factory(),
            'recipe_id' => Recipe::factory(),
            'day_of_week' => 1,
            'meal_type' => MealType::Dinner,
            'servings' => 4,
        ];
    }
}
