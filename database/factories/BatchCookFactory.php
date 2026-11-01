<?php

namespace Database\Factories;

use App\Models\BatchCook;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BatchCook>
 */
class BatchCookFactory extends Factory
{
    public function definition(): array
    {
        return [
            'created_by' => User::factory(),
            'recipe_id' => Recipe::factory(),
            'cooked_on' => now()->toDateString(),
            'prepared_portions' => 6,
        ];
    }
}
