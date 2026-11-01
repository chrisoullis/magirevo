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
            'media_type' => Media::TYPE_IMAGE,
            'source' => Media::SOURCE_LOCAL,
            'file_path' => fake()->unique()->bothify('recipe-media/????????-####.webp'),
            'mime_type' => 'image/webp',
            'file_size' => 1024,
            'width' => 1200,
            'height' => 900,
        ];
    }

    public function remote(?string $url = null): static
    {
        return $this->state(fn (): array => [
            'disk' => 'remote',
            'source' => Media::SOURCE_REMOTE,
            'file_path' => null,
            'url' => $url ?? fake()->unique()->imageUrl(),
            'mime_type' => null,
            'file_size' => null,
            'width' => null,
            'height' => null,
        ]);
    }
}
