<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\AppPermissions;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        if (filled(config('admin.email')) && filled(config('admin.password'))) {
            $admin = User::firstOrNew(['email' => config('admin.email')]);
            $admin->forceFill([
                'name' => config('admin.name'),
                'password' => config('admin.password'),
                'email_verified_at' => now(),
            ])->save();
            $admin->assignRole(AppPermissions::ADMIN);
        }

        $this->call(UnitSeeder::class);
        $this->call(AdditionalUnitSeeder::class);
        $this->call(CategorySeeder::class);
        $this->call(RecipeTagSeeder::class);
        $this->call(TagSeeder::class);
        $this->call(DifficultySeeder::class);
        $this->call(ScalingModeSeeder::class);
        $this->call(IngredientSeeder::class);
        $this->call(IngredientCatalogSeeder::class);
        if (! app()->isProduction()) {
            $this->call(RecipeSeeder::class);
            $this->call(RecipeContentSeeder::class);
        }
    }
}
