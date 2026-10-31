<?php

namespace Database\Factories;

use App\Models\Media;
use App\Models\MediaTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaTranslation>
 */
class MediaTranslationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'media_id' => Media::factory(),
            'locale' => 'en',
            'alt_text' => fake()->sentence(),
            'caption' => fake()->optional()->sentence(),
        ];
    }
}
