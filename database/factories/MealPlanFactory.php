<?php

namespace Database\Factories;

use App\Models\MealPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MealPlan>
 */
class MealPlanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'household_id' => null,
            'created_by' => User::factory(),
            'week_start' => MealPlan::weekStartFor(now())->toDateString(),
        ];
    }
}
