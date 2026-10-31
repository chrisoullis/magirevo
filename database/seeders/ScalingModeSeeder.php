<?php

namespace Database\Seeders;

use App\Models\ScalingMode;
use Illuminate\Database\Seeder;

class ScalingModeSeeder extends Seeder
{
    public function run(): void
    {
        $modes = [
            [
                'code' => 'linear',
                'sort_order' => 10,
                'en' => ['name' => 'Scales linearly', 'description' => 'The quantity grows or shrinks in proportion to the servings.'],
                'el' => ['name' => 'Κλιμακώνεται γραμμικά', 'description' => 'Η ποσότητα αυξάνεται ή μειώνεται ανάλογα με τις μερίδες.'],
            ],
            [
                'code' => 'fixed',
                'sort_order' => 20,
                'en' => ['name' => 'Fixed amount', 'description' => 'The quantity stays the same whatever the servings.'],
                'el' => ['name' => 'Σταθερή ποσότητα', 'description' => 'Η ποσότητα παραμένει ίδια ανεξάρτητα από τις μερίδες.'],
            ],
            [
                'code' => 'manual',
                'sort_order' => 30,
                'en' => ['name' => 'Manual', 'description' => 'The cook adjusts the quantity to taste.'],
                'el' => ['name' => 'Χειροκίνητη', 'description' => 'Ο μάγειρας προσαρμόζει την ποσότητα κατά το γούστο του.'],
            ],
        ];

        foreach ($modes as $data) {
            $mode = ScalingMode::query()->updateOrCreate(
                ['code' => $data['code']],
                ['sort_order' => $data['sort_order'], 'is_active' => true],
            );

            foreach (['en', 'el'] as $locale) {
                $mode->translations()->updateOrCreate(['locale' => $locale], $data[$locale]);
            }
        }
    }
}
