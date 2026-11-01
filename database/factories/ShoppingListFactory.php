<?php

namespace Database\Factories;

use App\Models\MealPlan;
use App\Models\ShoppingList;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShoppingList>
 */
class ShoppingListFactory extends Factory
{
    public function definition(): array
    {
        return ['meal_plan_id' => MealPlan::factory()];
    }
}
