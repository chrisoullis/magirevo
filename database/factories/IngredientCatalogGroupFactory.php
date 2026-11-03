<?php

namespace Database\Factories;

use App\Models\IngredientCatalogGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<IngredientCatalogGroup> */
class IngredientCatalogGroupFactory extends Factory
{
    public function definition(): array
    {
        return ['code' => fake()->unique()->bothify('catalog-group-????-####'), 'is_active' => true, 'sort_order' => 0];
    }
}
