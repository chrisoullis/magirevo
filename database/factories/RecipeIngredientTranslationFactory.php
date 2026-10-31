<?php

namespace Database\Factories;

use App\Models\RecipeIngredient;
use App\Models\RecipeIngredientTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecipeIngredientTranslation>
 */
class RecipeIngredientTranslationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'recipe_ingredient_id' => RecipeIngredient::factory(),
            'locale' => 'en',
            'preparation_note' => fake()->words(2, true),
            'quantity_text' => null,
        ];
    }
}
