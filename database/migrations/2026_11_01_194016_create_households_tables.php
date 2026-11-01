<?php

use App\Enums\HouseholdRole;
use App\Support\AppPermissions;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('households', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->timestamps();
        });

        Schema::create('household_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamps();
            $table->unique(['household_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('household_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('email', 254);
            $table->string('role', 20);
            $table->char('token_hash', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['household_id', 'email']);
        });

        $roles = $this->quoted(HouseholdRole::values());
        $invitable = $this->quoted(array_map(fn (HouseholdRole $role): string => $role->value, HouseholdRole::assignable()));

        DB::statement("ALTER TABLE household_members ADD CONSTRAINT household_members_role_check CHECK (role IN ({$roles}))");
        DB::statement("ALTER TABLE household_invitations ADD CONSTRAINT household_invitations_role_check CHECK (role IN ({$invitable}))");
        DB::statement('ALTER TABLE household_invitations ADD CONSTRAINT household_invitations_email_lowercase_check CHECK (email = lower(email))');

        // Exactly one owner per household, and one pending invitation per address.
        DB::statement("CREATE UNIQUE INDEX household_members_one_owner ON household_members (household_id) WHERE role = 'owner'");
        DB::statement('CREATE UNIQUE INDEX household_invitations_pending_unique ON household_invitations (household_id, email) WHERE accepted_at IS NULL AND revoked_at IS NULL');

        $this->grantPlannerPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('household_invitations');
        Schema::dropIfExists('household_members');
        Schema::dropIfExists('households');

        // Permissions are left in place: removing them could revoke access that administrators have since customised.
    }

    /**
     * Existing roles keep their current permissions; only the two new planner permissions are added.
     */
    private function grantPlannerPermissions(): void
    {
        (new RolesAndPermissionsSeeder)->run();

        $permissionIds = DB::table('permissions')
            ->whereIn('name', [AppPermissions::PLANNER_USE, AppPermissions::HOUSEHOLDS_CREATE])
            ->pluck('id');
        $roleIds = DB::table('roles')->whereIn('name', AppPermissions::ROLES)->pluck('id');

        $grants = [];

        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                $grants[] = ['permission_id' => $permissionId, 'role_id' => $roleId];
            }
        }

        DB::table('role_has_permissions')->insertOrIgnore($grants);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @param  list<string>  $values
     */
    private function quoted(array $values): string
    {
        return implode(', ', array_map(fn (string $value): string => "'{$value}'", $values));
    }
};
