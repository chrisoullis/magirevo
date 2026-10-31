<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            [
                'code' => 'weeknight',
                'sort_order' => 10,
                'translations' => [
                    'en' => ['name' => 'Weeknight', 'slug' => 'weeknight', 'description' => 'Simple recipes for weeknight meals.'],
                    'el' => ['name' => 'Καθημερινά', 'slug' => 'kathimerina', 'description' => 'Απλές συνταγές για τα καθημερινά γεύματα.'],
                ],
            ],
            [
                'code' => 'vegetarian',
                'sort_order' => 20,
                'translations' => [
                    'en' => ['name' => 'Vegetarian', 'slug' => 'vegetarian', 'description' => 'Vegetarian recipes for every occasion.'],
                    'el' => ['name' => 'Χορτοφαγικά', 'slug' => 'chortofagika', 'description' => 'Χορτοφαγικές συνταγές για κάθε περίσταση.'],
                ],
            ],
            [
                'code' => 'baking',
                'sort_order' => 30,
                'translations' => [
                    'en' => ['name' => 'Baking', 'slug' => 'baking', 'description' => 'Cakes, breads and other bakes.'],
                    'el' => ['name' => 'Αρτοσκευάσματα', 'slug' => 'artoskevasmata', 'description' => 'Κέικ, ψωμιά και άλλες δημιουργίες φούρνου.'],
                ],
            ],
            [
                'code' => 'brunch',
                'sort_order' => 40,
                'translations' => [
                    'en' => ['name' => 'Brunch', 'slug' => 'brunch', 'description' => 'Recipes for slow mornings and brunch.'],
                    'el' => ['name' => 'Μπραντς', 'slug' => 'brants', 'description' => 'Συνταγές για χαλαρά πρωινά και μπραντς.'],
                ],
            ],
        ];

        foreach ($categories as $categoryData) {
            $translations = $categoryData['translations'];
            unset($categoryData['translations']);

            $category = Category::query()->updateOrCreate(
                ['code' => $categoryData['code']],
                [...$categoryData, 'is_active' => true],
            );

            foreach ($translations as $locale => $translation) {
                $category->translations()->updateOrCreate(
                    ['locale' => $locale],
                    $translation,
                );
            }
        }
    }
}
