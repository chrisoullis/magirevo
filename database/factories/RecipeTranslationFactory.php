<?php

namespace Database\Factories;

use App\Models\Recipe;
use App\Models\RecipeTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecipeTranslation>
 */
class RecipeTranslationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->sentence(3);

        return [
            'recipe_id' => Recipe::factory(),
            'locale' => 'en',
            'title' => $title,
            'slug' => fake()->unique()->slug(),
            'summary' => fake()->sentence(),
            'introduction' => fake()->paragraph(),
            'seo_title' => $title,
            'seo_description' => fake()->sentence(),
        ];
    }
}
