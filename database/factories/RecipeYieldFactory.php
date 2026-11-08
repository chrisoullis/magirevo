<?php

namespace Database\Factories;

use App\Models\Recipe;
use App\Models\RecipeYield;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecipeYield>
 */
class RecipeYieldFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'recipe_id' => Recipe::factory(),
            'basis' => 'weight',
            'quantity' => '500.0000',
            'unit_id' => Unit::factory()->state(['unit_type' => 'mass', 'base_conversion' => '1.00000000']),
            'evidence' => ['method' => 'Measured after cooking'],
            'verified_by' => User::factory(),
            'verified_at' => now(),
        ];
    }
}
