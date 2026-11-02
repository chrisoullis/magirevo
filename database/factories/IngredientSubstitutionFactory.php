<?php

namespace Database\Factories;

use App\Models\Ingredient;
use App\Models\IngredientSubstitution;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IngredientSubstitution>
 */
class IngredientSubstitutionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ingredient_id' => Ingredient::factory(),
            'replacement_ingredient_id' => Ingredient::factory(),
            'recipe_id' => null,
            'status' => 'pending',
        ];
    }

    public function approved(): static
    {
        return $this->state(['status' => 'approved']);
    }
}
