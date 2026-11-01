<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Support\AppPermissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Idempotent: creates missing roles and permissions. A role receives its default permissions only when it
 * has none yet, so later changes made through the role interface are not overwritten on reseeding.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (AppPermissions::allPermissions() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (AppPermissions::defaultRolePermissions() as $roleName => $permissions) {
            $role = Role::findOrCreate($roleName, 'web');

            if ($role->permissions()->doesntExist()) {
                $role->syncPermissions($permissions);
            } elseif ($roleName === AppPermissions::ADMIN) {
                $role->givePermissionTo(AppPermissions::ADMIN_PROTECTED);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Seeds only when the application roles are missing; used by factories so tests need no explicit seeding.
     */
    public static function ensure(): void
    {
        if (Role::query()->whereIn('name', AppPermissions::ROLES)->count() < count(AppPermissions::ROLES)) {
            (new self)->run();
        }
    }
}
