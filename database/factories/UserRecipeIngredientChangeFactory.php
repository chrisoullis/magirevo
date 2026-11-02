<?php

namespace Database\Factories;

use App\Models\Ingredient;
use App\Models\RecipeIngredient;
use App\Models\User;
use App\Models\UserRecipeIngredientChange;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserRecipeIngredientChange>
 */
class UserRecipeIngredientChangeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'recipe_ingredient_id' => RecipeIngredient::factory(),
            'alternative_ingredient_id' => Ingredient::factory(),
            'note' => fake()->optional()->sentence(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (UserRecipeIngredientChange $change): void {
            $change->recipe_id = RecipeIngredient::query()
                ->whereKey($change->recipe_ingredient_id)
                ->value('recipe_id');
        });
    }
}
