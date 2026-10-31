<?php

namespace Database\Factories;

use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Recipe>
 */
class RecipeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'author_id' => User::factory(),
            'status' => 'draft',
            'category' => 'Weeknight',
            'prep_minutes' => fake()->numberBetween(5, 30),
            'cook_minutes' => fake()->numberBetween(5, 60),
            'rest_minutes' => 0,
            'base_servings' => 2,
            'servings' => 2,
            'difficulty' => 'Easy',
            'image_url' => 'https://example.com/recipe.jpg',
            'ingredients' => ['A test ingredient'],
            'instructions' => ['Prepare the recipe'],
            'is_featured' => false,
            'published_at' => null,
        ];
    }

    public function published(): static
    {
        return $this
            ->state(fn (): array => [
                'status' => 'published',
                'published_at' => now(),
            ])
            ->afterMaking(function (Recipe $recipe): void {
                $slug = fake()->unique()->slug();

                $recipe->fill([
                    'en' => ['title' => 'Published '.$slug, 'slug' => $slug],
                    'el' => ['title' => 'Δημοσιευμένη συνταγή '.$slug, 'slug' => $slug.'-el'],
                ]);
            });
    }
}
