<?php

namespace App\Policies;

use App\Models\RecipeReusePermission;
use App\Models\User;
use App\Support\AppPermissions;

/** Initial rights clearance and evidence access are reserved to explicitly authorized administrators. */
class RecipeReusePermissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasVerifiedEmail()
            && $user->hasRole(AppPermissions::ADMIN)
            && $user->can(AppPermissions::VARIATIONS_MANAGE_RIGHTS);
    }

    public function view(User $user, RecipeReusePermission $permission): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function suspend(User $user, RecipeReusePermission $permission): bool
    {
        return $this->viewAny($user) && $permission->suspended_at === null;
    }

    public function update(User $user, RecipeReusePermission $permission): bool
    {
        return false;
    }

    public function delete(User $user, RecipeReusePermission $permission): bool
    {
        return false;
    }
}
