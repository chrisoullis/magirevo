<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;
use App\Support\AppPermissions;

class RolePolicy
{
    private function allows(User $user, string $action): bool
    {
        return $user->hasVerifiedEmail() && $user->can($action.':Role');
    }

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'ViewAny');
    }

    public function view(User $user, Role $role): bool
    {
        return $this->allows($user, 'View');
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'Create');
    }

    /**
     * Only administrators may change the admin role, so no one can edit their way into it.
     */
    public function update(User $user, Role $role): bool
    {
        if ($role->name === AppPermissions::ADMIN && ! $user->hasRole(AppPermissions::ADMIN)) {
            return false;
        }

        return $this->allows($user, 'Update');
    }

    public function delete(User $user, Role $role): bool
    {
        return ! $role->isSystemRole() && $this->allows($user, 'Delete');
    }
}
