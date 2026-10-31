<?php

namespace Database\Seeders;

use App\Models\Difficulty;
use Illuminate\Database\Seeder;

class DifficultySeeder extends Seeder
{
    public function run(): void
    {
        $difficulties = [
            ['code' => 'Easy', 'sort_order' => 10, 'en' => 'Easy', 'el' => 'Εύκολη'],
            ['code' => 'Medium', 'sort_order' => 20, 'en' => 'Medium', 'el' => 'Μέτρια'],
            ['code' => 'A little project', 'sort_order' => 30, 'en' => 'A little project', 'el' => 'Μικρό project'],
        ];

        foreach ($difficulties as $data) {
            $difficulty = Difficulty::query()->updateOrCreate(
                ['code' => $data['code']],
                ['sort_order' => $data['sort_order'], 'is_active' => true],
            );

            foreach (['en', 'el'] as $locale) {
                $difficulty->translations()->updateOrCreate(['locale' => $locale], ['name' => $data[$locale]]);
            }
        }
    }
}
