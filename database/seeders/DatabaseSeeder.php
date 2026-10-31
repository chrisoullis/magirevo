<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (filled(config('admin.email')) && filled(config('admin.password'))) {
            $admin = User::firstOrNew(['email' => config('admin.email')]);
            $admin->forceFill([
                'name' => config('admin.name'),
                'password' => config('admin.password'),
                'email_verified_at' => now(),
                'is_admin' => true,
            ])->save();
        }

        $this->call(UnitSeeder::class);
        $this->call(CategorySeeder::class);
        $this->call(TagSeeder::class);
        $this->call(DifficultySeeder::class);
        $this->call(ScalingModeSeeder::class);
        $this->call(IngredientSeeder::class);
        $this->call(RecipeSeeder::class);
        $this->call(RecipeContentSeeder::class);
    }
}
