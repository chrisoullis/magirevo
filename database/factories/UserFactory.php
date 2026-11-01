<?php

namespace Database\Factories;

use App\Models\User;
use App\Support\AppPermissions;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (User $user): void {
            if ($user->roles()->doesntExist()) {
                $this->grantRole($user, AppPermissions::MEMBER);
            }
        });
    }

    public function admin(): static
    {
        return $this->afterCreating(fn (User $user) => $this->grantRole($user, AppPermissions::ADMIN));
    }

    public function editor(): static
    {
        return $this->afterCreating(fn (User $user) => $this->grantRole($user, AppPermissions::EDITOR));
    }

    public function contributor(): static
    {
        return $this->afterCreating(fn (User $user) => $this->grantRole($user, AppPermissions::CONTRIBUTOR));
    }

    private function grantRole(User $user, string $role): void
    {
        RolesAndPermissionsSeeder::ensure();

        $user->syncRoles([$role]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
