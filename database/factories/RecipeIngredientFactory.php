<?php

namespace Database\Factories;

use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\ScalingMode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecipeIngredient>
 */
class RecipeIngredientFactory extends Factory
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
            'ingredient_id' => Ingredient::factory(),
            'ingredient_group_id' => null,
            'quantity' => '1.0000',
            'quantity_max' => null,
            'unit_id' => null,
            'scaling_mode' => fn (): string => ScalingMode::query()->firstOrCreate(['code' => 'linear'], ['sort_order' => 10])->code,
            'is_optional' => false,
            'sort_order' => 0,
        ];
    }
}
