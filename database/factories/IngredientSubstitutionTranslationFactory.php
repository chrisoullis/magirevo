<?php

namespace Database\Factories;

use App\Models\IngredientSubstitution;
use App\Models\IngredientSubstitutionTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IngredientSubstitutionTranslation>
 */
class IngredientSubstitutionTranslationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ingredient_substitution_id' => IngredientSubstitution::factory(),
            'locale' => 'en',
            'explanation' => fake()->sentence(),
        ];
    }
}
