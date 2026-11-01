<?php

namespace Database\Seeders;

use App\Models\Unit;
use Illuminate\Database\Seeder;

/**
 * Cooking units added for the meal planner. Idempotent, and kept apart from UnitSeeder so the original seven are untouched.
 *
 * Clove and pinch are counts of their own kind: they have no gram or millilitre equivalent and are never merged with
 * other units. The cup is the metric 250 ml cup, labelled as such because other cup sizes exist.
 */
class AdditionalUnitSeeder extends Seeder
{
    public function run(): void
    {
        $units = [
            [
                'code' => 'cup_metric',
                'unit_type' => 'volume',
                'base_conversion' => '250.00000000',
                'sort_order' => 80,
                'translations' => [
                    'en' => ['name' => 'metric cup (250 ml)', 'abbreviation' => 'metric cup'],
                    'el' => ['name' => 'μετρικό φλιτζάνι (250 ml)', 'abbreviation' => 'μετρ. φλ.'],
                ],
            ],
            [
                'code' => 'clove',
                'unit_type' => 'count',
                'base_conversion' => '1.00000000',
                'sort_order' => 90,
                'translations' => [
                    'en' => ['name' => 'clove', 'abbreviation' => 'clove'],
                    'el' => ['name' => 'σκελίδα', 'abbreviation' => 'σκ.'],
                ],
            ],
            [
                'code' => 'pinch',
                'unit_type' => 'count',
                'base_conversion' => '1.00000000',
                'sort_order' => 100,
                'translations' => [
                    'en' => ['name' => 'pinch', 'abbreviation' => 'pinch'],
                    'el' => ['name' => 'πρέζα', 'abbreviation' => 'πρέζα'],
                ],
            ],
        ];

        foreach ($units as $data) {
            $translations = $data['translations'];
            unset($data['translations']);

            $unit = Unit::query()->updateOrCreate(
                ['code' => $data['code']],
                [...$data, 'allow_fraction' => true, 'is_scalable' => true],
            );

            foreach ($translations as $locale => $translation) {
                $unit->translations()->updateOrCreate(['locale' => $locale], $translation);
            }
        }
    }
}
