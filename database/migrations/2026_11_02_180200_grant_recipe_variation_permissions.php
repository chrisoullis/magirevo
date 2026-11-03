<?php

use App\Support\AppPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $defaults = AppPermissions::defaultRolePermissions();

        foreach (AppPermissions::VARIATION_PERMISSIONS as $permission) {
            DB::table('permissions')->insertOrIgnore([
                'name' => $permission,
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $permissionId = DB::table('permissions')->where('name', $permission)->where('guard_name', 'web')->value('id');

            foreach (AppPermissions::ROLES as $roleName) {
                if (! in_array($permission, $defaults[$roleName], true)) {
                    continue;
                }

                $roleId = DB::table('roles')->where('name', $roleName)->where('guard_name', 'web')->value('id');

                if ($roleId !== null) {
                    DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')->whereIn('name', AppPermissions::VARIATION_PERMISSIONS)->where('guard_name', 'web')->pluck('id');

        DB::table('role_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
