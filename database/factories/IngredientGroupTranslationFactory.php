<?php

namespace Database\Factories;

use App\Models\IngredientGroup;
use App\Models\IngredientGroupTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IngredientGroupTranslation>
 */
class IngredientGroupTranslationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ingredient_group_id' => IngredientGroup::factory(),
            'locale' => 'en',
            'name' => fake()->words(2, true),
        ];
    }
}
