<?php

namespace Database\Factories;

use App\Models\Ingredient;
use App\Models\IngredientTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IngredientTranslation>
 */
class IngredientTranslationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'ingredient_id' => Ingredient::factory(),
            'locale' => 'en',
            'name' => $name,
            'plural_name' => Str::plural($name),
            'description' => fake()->sentence(),
        ];
    }
}
