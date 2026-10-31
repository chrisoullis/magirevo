<?php

namespace Database\Factories;

use App\Models\ScalingMode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScalingMode>
 */
class ScalingModeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->lexify('mode-?????'),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
