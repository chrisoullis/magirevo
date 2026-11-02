<?php

namespace Database\Seeders;

use App\Models\Tag;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;

class TagSeeder extends Seeder
{
    public function run(): void
    {
        $tags = [
            [
                'code' => 'quick',
                'classification' => 'descriptive',
                'is_public_enabled' => false,
                'sort_order' => 10,
                'translations' => [
                    'en' => ['name' => 'Quick', 'slug' => 'quick', 'description' => 'Recipes ready in a short time.'],
                    'el' => ['name' => 'Γρήγορα', 'slug' => 'grigora', 'description' => 'Συνταγές που ετοιμάζονται σε λίγο χρόνο.'],
                ],
            ],
            [
                'code' => 'vegetarian',
                'classification' => 'diet_style',
                'is_public_enabled' => true,
                'sort_order' => 20,
                'translations' => [
                    'en' => ['name' => 'Vegetarian', 'slug' => 'vegetarian', 'description' => 'Recipes without meat or fish.'],
                    'el' => ['name' => 'Χορτοφαγικά', 'slug' => 'chortofagika', 'description' => 'Συνταγές χωρίς κρέας ή ψάρι.'],
                ],
            ],
            [
                'code' => 'vegan',
                'classification' => 'diet_style',
                'is_public_enabled' => true,
                'sort_order' => 25,
                'translations' => [
                    'en' => ['name' => 'Vegan', 'slug' => 'vegan', 'description' => 'Recipes without animal-derived ingredients, as far as our review can tell.'],
                    'el' => ['name' => 'Vegan', 'slug' => 'vegan', 'description' => 'Συνταγές χωρίς υλικά ζωικής προέλευσης, με βάση τον έλεγχό μας.'],
                ],
            ],
            [
                'code' => 'budget-friendly',
                'classification' => 'descriptive',
                'is_public_enabled' => false,
                'sort_order' => 30,
                'translations' => [
                    'en' => ['name' => 'Budget friendly', 'slug' => 'budget-friendly', 'description' => 'Delicious recipes made with affordable ingredients.'],
                    'el' => ['name' => 'Οικονομικά', 'slug' => 'oikonomika', 'description' => 'Νόστιμες συνταγές με οικονομικά υλικά.'],
                ],
            ],
            [
                'code' => 'baking',
                'classification' => 'descriptive',
                'is_public_enabled' => false,
                'sort_order' => 40,
                'translations' => [
                    'en' => ['name' => 'Baking', 'slug' => 'baking', 'description' => 'Recipes for baking and pastry.'],
                    'el' => ['name' => 'Αρτοσκευάσματα', 'slug' => 'artopoiia', 'description' => 'Συνταγές για ψήσιμο και αρτοσκευάσματα.'],
                ],
            ],
            [
                'code' => 'gluten-free',
                'classification' => 'ingredient_allergen',
                'is_public_enabled' => false,
                'sort_order' => 50,
                'translations' => [
                    'en' => ['name' => 'Gluten free', 'slug' => 'gluten-free', 'description' => 'Recipes made without gluten-containing ingredients.'],
                    'el' => ['name' => 'Χωρίς γλουτένη', 'slug' => 'choris-glouteni', 'description' => 'Συνταγές χωρίς υλικά που περιέχουν γλουτένη.'],
                ],
            ],
        ];

        foreach ($tags as $tagData) {
            $translations = $tagData['translations'];
            // Classification is set on creation only, so reseeding never undoes a reviewer's decision.
            $classification = Arr::only($tagData, ['classification', 'is_public_enabled']);
            unset($tagData['translations'], $tagData['classification'], $tagData['is_public_enabled']);

            $tag = Tag::query()->firstOrCreate(
                ['code' => $tagData['code']],
                [...$tagData, 'is_active' => true],
            );

            if ($tag->wasRecentlyCreated) {
                $tag->update($classification);
            }

            foreach ($translations as $locale => $translation) {
                $tag->translations()->firstOrCreate(
                    ['locale' => $locale],
                    $translation,
                );
            }
        }
    }
}
