<?php

namespace Database\Seeders;

use App\Models\Tag;
use Illuminate\Database\Seeder;

class TagSeeder extends Seeder
{
    public function run(): void
    {
        $tags = [
            [
                'code' => 'quick',
                'sort_order' => 10,
                'translations' => [
                    'en' => ['name' => 'Quick', 'slug' => 'quick', 'description' => 'Recipes ready in a short time.'],
                    'el' => ['name' => 'Γρήγορα', 'slug' => 'grigora', 'description' => 'Συνταγές που ετοιμάζονται σε λίγο χρόνο.'],
                ],
            ],
            [
                'code' => 'vegetarian',
                'sort_order' => 20,
                'translations' => [
                    'en' => ['name' => 'Vegetarian', 'slug' => 'vegetarian', 'description' => 'Recipes without meat or fish.'],
                    'el' => ['name' => 'Χορτοφαγικά', 'slug' => 'chortofagika', 'description' => 'Συνταγές χωρίς κρέας ή ψάρι.'],
                ],
            ],
            [
                'code' => 'budget-friendly',
                'sort_order' => 30,
                'translations' => [
                    'en' => ['name' => 'Budget friendly', 'slug' => 'budget-friendly', 'description' => 'Delicious recipes made with affordable ingredients.'],
                    'el' => ['name' => 'Οικονομικά', 'slug' => 'oikonomika', 'description' => 'Νόστιμες συνταγές με οικονομικά υλικά.'],
                ],
            ],
            [
                'code' => 'baking',
                'sort_order' => 40,
                'translations' => [
                    'en' => ['name' => 'Baking', 'slug' => 'baking', 'description' => 'Recipes for baking and pastry.'],
                    'el' => ['name' => 'Αρτοσκευάσματα', 'slug' => 'artopoiia', 'description' => 'Συνταγές για ψήσιμο και αρτοσκευάσματα.'],
                ],
            ],
            [
                'code' => 'gluten-free',
                'sort_order' => 50,
                'translations' => [
                    'en' => ['name' => 'Gluten free', 'slug' => 'gluten-free', 'description' => 'Recipes made without gluten-containing ingredients.'],
                    'el' => ['name' => 'Χωρίς γλουτένη', 'slug' => 'choris-glouteni', 'description' => 'Συνταγές χωρίς υλικά που περιέχουν γλουτένη.'],
                ],
            ],
        ];

        foreach ($tags as $tagData) {
            $translations = $tagData['translations'];
            unset($tagData['translations']);

            $tag = Tag::query()->updateOrCreate(
                ['code' => $tagData['code']],
                [...$tagData, 'is_active' => true],
            );

            foreach ($translations as $locale => $translation) {
                $tag->translations()->updateOrCreate(
                    ['locale' => $locale],
                    $translation,
                );
            }
        }
    }
}
