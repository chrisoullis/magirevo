<?php

namespace Database\Factories;

use App\Models\Ingredient;
use App\Models\IngredientAlias;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<IngredientAlias> */
class IngredientAliasFactory extends Factory
{
    public function definition(): array
    {
        return ['ingredient_id' => Ingredient::factory(), 'locale' => 'en', 'alias' => fake()->unique()->words(2, true)];
    }
}
