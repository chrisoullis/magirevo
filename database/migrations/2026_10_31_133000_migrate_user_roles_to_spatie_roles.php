<?php

use App\Support\AppPermissions;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Gives every existing user the Spatie role matching the legacy users.role value.
     * The legacy column is left untouched and is dropped in a later, separate migration.
     */
    public function up(): void
    {
        (new RolesAndPermissionsSeeder)->run();

        $roleIds = DB::table('roles')->where('guard_name', 'web')->pluck('id', 'name');

        foreach (DB::table('users')->select('id', 'role')->orderBy('id')->cursor() as $user) {
            $roleName = in_array($user->role, AppPermissions::ROLES, true) ? $user->role : AppPermissions::MEMBER;

            DB::table('model_has_roles')->insertOrIgnore([
                'role_id' => $roleIds[$roleName],
                'model_type' => 'App\\Models\\User',
                'model_id' => $user->id,
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('model_has_roles')->where('model_type', 'App\\Models\\User')->delete();
    }
};
