<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Recipe;
use App\Models\Role;
use App\Models\User;
use App\Services\RoleAssignmentService;
use App\Support\AppPermissions;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class RolesAndPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_users_default_to_member(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($user->hasRole(AppPermissions::MEMBER));
        $this->assertFalse($user->can('ViewAny:Recipe'));
    }

    public function test_seeding_is_idempotent(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $roles = Role::count();
        $permissions = Permission::count();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame($roles, Role::count());
        $this->assertSame($permissions, Permission::count());
    }

    public function test_editor_is_read_only_on_lookups_but_can_edit_recipes(): void
    {
        $editor = User::factory()->editor()->create();

        $this->assertTrue($editor->can('viewAny', Category::class));
        $this->assertFalse($editor->can('create', Category::class));
        $this->assertTrue($editor->can('create', Recipe::class));
        $this->assertFalse($editor->can('delete', Recipe::factory()->create()));
        $this->assertFalse($editor->can('publish', Recipe::factory()->create()));
    }

    public function test_administrator_manages_everything_except_force_delete(): void
    {
        $admin = User::factory()->admin()->create();
        $recipe = Recipe::factory()->create();

        $this->assertTrue($admin->can('create', Category::class));
        $this->assertTrue($admin->can('delete', $recipe));
        $this->assertTrue($admin->can('publish', $recipe));
        $this->assertFalse($admin->can('forceDelete', $recipe));
    }

    public function test_only_staff_can_access_admin_panel(): void
    {
        $this->assertTrue(User::factory()->admin()->create()->canAccessPanel(Filament::getPanel('admin')));
        $this->assertTrue(User::factory()->editor()->create()->canAccessPanel(Filament::getPanel('admin')));
        $this->assertFalse(User::factory()->contributor()->create()->canAccessPanel(Filament::getPanel('admin')));
        $this->assertFalse(User::factory()->create()->canAccessPanel(Filament::getPanel('admin')));
    }

    public function test_unverified_administrator_has_no_access(): void
    {
        $admin = User::factory()->admin()->unverified()->create();

        $this->assertFalse($admin->can('viewAny', Recipe::class));
    }

    public function test_only_administrators_can_edit_roles(): void
    {
        $adminRole = Role::findByName(AppPermissions::ADMIN);
        User::factory()->admin()->create();

        $this->assertTrue(User::factory()->admin()->create()->can('update', $adminRole));
        $this->assertFalse(User::factory()->editor()->create()->can('viewAny', Role::class));
    }

    public function test_system_roles_cannot_be_deleted_or_renamed(): void
    {
        User::factory()->create();
        $role = Role::findByName(AppPermissions::EDITOR);

        $this->assertFalse(User::factory()->admin()->create()->can('delete', $role));

        $this->expectException(\Throwable::class);
        $role->update(['name' => 'renamed']);
    }

    public function test_admin_role_keeps_protected_permissions(): void
    {
        User::factory()->create();
        $admin = Role::findByName(AppPermissions::ADMIN);

        $admin->syncPermissions([]);

        $this->assertTrue($admin->fresh()->hasPermissionTo(AppPermissions::ROLES_MANAGE));
        $this->assertTrue($admin->fresh()->hasPermissionTo(AppPermissions::USERS_MANAGE));
    }

    public function test_cannot_assign_roles_without_permission_or_to_self(): void
    {
        $service = app(RoleAssignmentService::class);
        $editor = User::factory()->editor()->create();
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();

        try {
            $service->assign($editor, $member, AppPermissions::ADMIN);
            $this->fail('Editor escalated privileges.');
        } catch (AuthorizationException) {
        }

        try {
            $service->assign($admin, $admin, AppPermissions::EDITOR);
            $this->fail('Self role change allowed.');
        } catch (AuthorizationException) {
        }

        $this->assertFalse($member->fresh()->hasRole(AppPermissions::ADMIN));
    }

    public function test_administrator_can_assign_and_revoke_roles(): void
    {
        $service = app(RoleAssignmentService::class);
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();

        $service->assign($admin, $member, AppPermissions::CONTRIBUTOR);
        $this->assertTrue($member->fresh()->hasRole(AppPermissions::CONTRIBUTOR));

        $service->revoke($admin, $member, AppPermissions::CONTRIBUTOR);
        $this->assertFalse($member->fresh()->hasRole(AppPermissions::CONTRIBUTOR));

        $this->expectException(ValidationException::class);
        $service->assign($admin, $member, 'nonexistent');
    }
}
