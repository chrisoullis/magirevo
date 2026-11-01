<?php

namespace Database\Factories;

use App\Enums\BatchMovementType;
use App\Models\BatchCook;
use App\Models\BatchCookMovement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BatchCookMovement>
 */
class BatchCookMovementFactory extends Factory
{
    public function definition(): array
    {
        return ['batch_cook_id' => BatchCook::factory(), 'type' => BatchMovementType::Prepared, 'portions' => 1];
    }
}
