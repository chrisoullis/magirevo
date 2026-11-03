<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const ACTIONS = ['ViewAny', 'View', 'Create', 'Update', 'Delete', 'DeleteAny', 'Restore', 'RestoreAny', 'ForceDelete', 'ForceDeleteAny', 'Replicate', 'Reorder'];

    public function up(): void
    {
        foreach (self::ACTIONS as $action) {
            $name = $action.':IngredientCatalogGroup';
            DB::table('permissions')->insertOrIgnore(['name' => $name, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
            $permissionId = DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id');
            $roles = str_starts_with($action, 'ForceDelete') ? [] : ['admin'];

            if (in_array($action, ['ViewAny', 'View'], true)) {
                $roles[] = 'editor';
            }

            foreach ($roles as $role) {
                $roleId = DB::table('roles')->where('name', $role)->where('guard_name', 'web')->value('id');

                if ($roleId !== null) {
                    DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $names = array_map(fn (string $action): string => $action.':IngredientCatalogGroup', self::ACTIONS);
        $ids = DB::table('permissions')->whereIn('name', $names)->where('guard_name', 'web')->pluck('id');
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
