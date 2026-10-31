<?php

namespace Database\Seeders;

use App\Models\Unit;
use Illuminate\Database\Seeder;

class UnitSeeder extends Seeder
{
    public function run(): void
    {
        $units = [
            [
                'code' => 'g',
                'unit_type' => 'mass',
                'base_conversion' => '1.00000000',
                'allow_fraction' => true,
                'is_scalable' => true,
                'sort_order' => 10,
                'translations' => [
                    'en' => ['name' => 'gram', 'abbreviation' => 'g'],
                    'el' => ['name' => 'γραμμάριο', 'abbreviation' => 'γρ.'],
                ],
            ],
            [
                'code' => 'kg',
                'unit_type' => 'mass',
                'base_conversion' => '1000.00000000',
                'allow_fraction' => true,
                'is_scalable' => true,
                'sort_order' => 20,
                'translations' => [
                    'en' => ['name' => 'kilogram', 'abbreviation' => 'kg'],
                    'el' => ['name' => 'κιλό', 'abbreviation' => 'κιλά'],
                ],
            ],
            [
                'code' => 'ml',
                'unit_type' => 'volume',
                'base_conversion' => '1.00000000',
                'allow_fraction' => true,
                'is_scalable' => true,
                'sort_order' => 30,
                'translations' => [
                    'en' => ['name' => 'milliliter', 'abbreviation' => 'ml'],
                    'el' => ['name' => 'χιλιοστόλιτρο', 'abbreviation' => 'ml'],
                ],
            ],
            [
                'code' => 'l',
                'unit_type' => 'volume',
                'base_conversion' => '1000.00000000',
                'allow_fraction' => true,
                'is_scalable' => true,
                'sort_order' => 40,
                'translations' => [
                    'en' => ['name' => 'liter', 'abbreviation' => 'l'],
                    'el' => ['name' => 'λίτρο', 'abbreviation' => 'l'],
                ],
            ],
            [
                'code' => 'piece',
                'unit_type' => 'count',
                'base_conversion' => '1.00000000',
                'allow_fraction' => true,
                'is_scalable' => true,
                'sort_order' => 50,
                'translations' => [
                    'en' => ['name' => 'piece', 'abbreviation' => 'pc'],
                    'el' => ['name' => 'τεμάχιο', 'abbreviation' => 'τεμ.'],
                ],
            ],
            [
                'code' => 'tsp',
                'unit_type' => 'volume',
                'base_conversion' => '5.00000000',
                'allow_fraction' => true,
                'is_scalable' => true,
                'sort_order' => 60,
                'translations' => [
                    'en' => ['name' => 'teaspoon', 'abbreviation' => 'tsp'],
                    'el' => ['name' => 'κουταλάκι του γλυκού', 'abbreviation' => 'κ.γ.'],
                ],
            ],
            [
                'code' => 'tbsp',
                'unit_type' => 'volume',
                'base_conversion' => '15.00000000',
                'allow_fraction' => true,
                'is_scalable' => true,
                'sort_order' => 70,
                'translations' => [
                    'en' => ['name' => 'tablespoon', 'abbreviation' => 'tbsp'],
                    'el' => ['name' => 'κουταλιά της σούπας', 'abbreviation' => 'κ.σ.'],
                ],
            ],
        ];

        foreach ($units as $unitData) {
            $translations = $unitData['translations'];
            unset($unitData['translations']);

            $unit = Unit::query()->updateOrCreate(
                ['code' => $unitData['code']],
                $unitData,
            );

            foreach ($translations as $locale => $translation) {
                $unit->translations()->updateOrCreate(
                    ['locale' => $locale],
                    $translation,
                );
            }
        }
    }
}
