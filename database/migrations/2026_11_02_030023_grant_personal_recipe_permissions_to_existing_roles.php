<?php

use App\Support\AppPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $roleIds = DB::table('roles')
            ->whereIn('name', AppPermissions::ROLES)
            ->where('guard_name', 'web')
            ->pluck('id');

        foreach ([AppPermissions::RECIPES_PERSONALIZE, AppPermissions::RECIPES_RATE] as $permission) {
            DB::table('permissions')->insertOrIgnore([
                'name' => $permission,
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $permissionId = DB::table('permissions')
                ->where('name', $permission)
                ->where('guard_name', 'web')
                ->value('id');

            $grants = $roleIds
                ->map(fn (int $roleId): array => ['permission_id' => $permissionId, 'role_id' => $roleId])
                ->all();

            DB::table('role_has_permissions')->insertOrIgnore($grants);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('name', [AppPermissions::RECIPES_PERSONALIZE, AppPermissions::RECIPES_RATE])
            ->where('guard_name', 'web')
            ->pluck('id');

        DB::table('role_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
