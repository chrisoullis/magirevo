<?php

namespace Database\Factories;

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\HouseholdInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<HouseholdInvitation>
 */
class HouseholdInvitationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'email' => Str::lower(fake()->unique()->safeEmail()),
            'role' => HouseholdRole::Member,
            'token_hash' => HouseholdInvitation::hashToken(Str::random(48)),
            'expires_at' => now()->addDays(7),
        ];
    }
}
