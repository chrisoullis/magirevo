<?php

namespace Database\Factories;

use App\Models\Tag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tag>
 */
class TagFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->slug(2),
            'classification' => 'descriptive',
            'is_public_enabled' => false,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function dietary(string $classification = 'diet_style', bool $publicEnabled = true): static
    {
        return $this->state(fn (): array => ['classification' => $classification, 'is_public_enabled' => $publicEnabled]);
    }
}
