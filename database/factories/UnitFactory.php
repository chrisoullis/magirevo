<?php

namespace Database\Factories;

use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('unit-????-####'),
            'unit_type' => 'mass',
            'base_conversion' => '1.00000000',
            'allow_fraction' => true,
            'is_scalable' => true,
            'sort_order' => 0,
        ];
    }
}
