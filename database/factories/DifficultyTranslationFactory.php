<?php

namespace Database\Factories;

use App\Models\Difficulty;
use App\Models\DifficultyTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DifficultyTranslation>
 */
class DifficultyTranslationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'difficulty_id' => Difficulty::factory(),
            'locale' => 'en',
            'name' => fake()->unique()->words(2, true),
            'description' => fake()->sentence(),
        ];
    }
}
