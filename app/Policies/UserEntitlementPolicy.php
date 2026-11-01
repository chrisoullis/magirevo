<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserEntitlement;
use App\Support\AppPermissions;

class UserEntitlementPolicy
{
    private function manages(User $user): bool
    {
        return $user->hasVerifiedEmail() && $user->can(AppPermissions::QUOTAS_MANAGE);
    }

    public function viewAny(User $user): bool
    {
        return $this->manages($user);
    }

    public function view(User $user, UserEntitlement $entitlement): bool
    {
        return $this->manages($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, UserEntitlement $entitlement): bool
    {
        return $this->manages($user);
    }

    public function delete(User $user, UserEntitlement $entitlement): bool
    {
        return false;
    }
}
