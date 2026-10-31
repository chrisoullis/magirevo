<?php

namespace Database\Factories;

use App\Models\Unit;
use App\Models\UnitTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UnitTranslation>
 */
class UnitTranslationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'unit_id' => Unit::factory(),
            'locale' => 'en',
            'name' => $name,
            'abbreviation' => $name,
        ];
    }
}
