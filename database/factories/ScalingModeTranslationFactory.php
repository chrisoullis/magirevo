<?php

namespace Database\Factories;

use App\Models\ScalingMode;
use App\Models\ScalingModeTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScalingModeTranslation>
 */
class ScalingModeTranslationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scaling_mode_id' => ScalingMode::factory(),
            'locale' => 'en',
            'name' => fake()->unique()->words(2, true),
            'description' => fake()->sentence(),
        ];
    }
}
