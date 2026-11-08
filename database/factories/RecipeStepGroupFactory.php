<?php

namespace Database\Factories;

use App\Models\Recipe;
use App\Models\RecipeStepGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RecipeStepGroup> */
class RecipeStepGroupFactory extends Factory
{
    public function definition(): array
    {
        return ['recipe_id' => Recipe::factory(), 'position' => 0];
    }
}
