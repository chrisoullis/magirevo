<?php

namespace Database\Factories;

use App\Models\MealPlanTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MealPlanTemplate>
 */
class MealPlanTemplateFactory extends Factory
{
    public function definition(): array
    {
        return ['created_by' => User::factory(), 'name' => fake()->words(2, true)];
    }
}
