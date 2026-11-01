<?php

namespace Database\Factories;

use App\Enums\MealType;
use App\Models\MealPlan;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MealPlanEntry>
 */
class MealPlanEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'meal_plan_id' => MealPlan::factory(),
            'recipe_id' => Recipe::factory(),
            'planned_on' => MealPlan::weekStartFor(now())->toDateString(),
            'meal_type' => MealType::Dinner,
            'servings' => 4,
        ];
    }
}
