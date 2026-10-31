<?php

namespace Database\Factories;

use App\Models\Media;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Media>
 */
class MediaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'disk' => 'public',
            'file_path' => fake()->unique()->bothify('recipe-media/????????-####.webp'),
            'mime_type' => 'image/webp',
            'file_size' => 1024,
            'width' => 1200,
            'height' => 900,
        ];
    }
}
