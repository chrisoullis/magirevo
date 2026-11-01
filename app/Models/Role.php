<?php

namespace App\Models;

use App\Support\AppPermissions;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * The four application roles are system roles: they cannot be renamed or deleted,
 * and the admin role can never lose the permissions needed to administer roles and users.
 */
class Role extends SpatieRole
{
    protected static function booted(): void
    {
        static::updating(function (Role $role): void {
            if ($role->isDirty('name') && in_array($role->getOriginal('name'), AppPermissions::ROLES, true)) {
                throw ValidationException::withMessages(['name' => 'System roles cannot be renamed.']);
            }
        });

        static::deleting(function (Role $role): void {
            if ($role->isSystemRole()) {
                throw ValidationException::withMessages(['name' => 'System roles cannot be deleted.']);
            }
        });
    }

    public function isSystemRole(): bool
    {
        return in_array($this->name, AppPermissions::ROLES, true);
    }

    public function syncPermissions(...$permissions): static
    {
        if ($this->name === AppPermissions::ADMIN) {
            $permissions = [...$permissions, ...AppPermissions::ADMIN_PROTECTED];
        }

        return parent::syncPermissions(...$permissions);
    }

    public function revokePermissionTo($permission): static
    {
        $name = is_string($permission) ? $permission : ($permission->name ?? null);

        if ($this->name === AppPermissions::ADMIN && in_array($name, AppPermissions::ADMIN_PROTECTED, true)) {
            throw ValidationException::withMessages(['permissions' => "The admin role must keep the {$name} permission."]);
        }

        return parent::revokePermissionTo($permission);
    }
}
