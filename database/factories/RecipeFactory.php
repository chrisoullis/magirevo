<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Difficulty;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
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
            'visibility' => 'private',
            'prep_minutes' => fake()->numberBetween(5, 30),
            'cook_minutes' => fake()->numberBetween(5, 60),
            'rest_minutes' => 0,
            'base_servings' => 2,
            'difficulty' => fn (): string => Difficulty::query()->firstOrCreate(['code' => 'Easy'], ['sort_order' => 10])->code,
            'is_featured' => false,
            'published_at' => null,
        ];
    }

    public function shared(): static
    {
        return $this->state(['visibility' => 'shared']);
    }

    public function pending(): static
    {
        return $this->state(['status' => 'pending']);
    }

    public function published(): static
    {
        return $this
            ->state(fn (): array => [
                'status' => 'published',
                'visibility' => 'public',
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

    /**
     * A draft with everything required to pass the publication gate.
     */
    public function complete(): static
    {
        return $this
            ->afterMaking(function (Recipe $recipe): void {
                $slug = fake()->unique()->slug();

                $recipe->fill([
                    'en' => ['title' => 'Recipe '.$slug, 'slug' => $slug, 'summary' => 'A short English summary.'],
                    'el' => ['title' => 'Συνταγή '.$slug, 'slug' => $slug.'-el', 'summary' => 'Μια σύντομη περίληψη.'],
                ]);
            })
            ->afterCreating(function (Recipe $recipe): void {
                $category = Category::factory()->create();
                $recipe->categories()->attach($category);

                RecipeIngredient::factory()->for($recipe)->create()->ingredient->fill([
                    'en' => ['name' => 'Test ingredient '.fake()->unique()->numerify('####')],
                    'el' => ['name' => 'Υλικό '.fake()->unique()->numerify('####')],
                ])->save();

                $step = $recipe->steps()->create(['step_number' => 1]);
                $step->fill(['en' => ['instruction' => 'Cook it.'], 'el' => ['instruction' => 'Μαγειρέψτε το.']])->save();

                $media = Media::factory()->remote()->create();
                $media->fill(['en' => ['alt_text' => 'Dish'], 'el' => ['alt_text' => 'Πιάτο']])->save();
                $recipe->media()->attach($media, ['role' => 'cover', 'sort_order' => 0]);
            });
    }
}
