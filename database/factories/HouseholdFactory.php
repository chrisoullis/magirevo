<?php

namespace Database\Factories;

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Household>
 */
class HouseholdFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->lastName().' household'];
    }

    public function ownedBy(User $user): static
    {
        return $this->afterCreating(fn (Household $household) => $household->members()->create(['user_id' => $user->getKey(), 'role' => HouseholdRole::Owner]));
    }

    public function withMember(User $user, HouseholdRole $role): static
    {
        return $this->afterCreating(fn (Household $household) => $household->members()->create(['user_id' => $user->getKey(), 'role' => $role]));
    }
}
