<?php

namespace Database\Factories;

use App\Models\RecipeStep;
use App\Models\RecipeStepTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecipeStepTranslation>
 */
class RecipeStepTranslationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'recipe_step_id' => RecipeStep::factory(),
            'locale' => 'en',
            'instruction' => fake()->sentence(),
        ];
    }
}
