<?php

namespace App\Policies;

use App\Models\Plan;
use App\Models\User;
use App\Support\AppPermissions;

/**
 * Plans are configured by administrators only. They are never created or deleted through the UI,
 * so plan codes stay stable.
 */
class PlanPolicy
{
    private function manages(User $user): bool
    {
        return $user->hasVerifiedEmail() && $user->can(AppPermissions::QUOTAS_MANAGE);
    }

    public function viewAny(User $user): bool
    {
        return $this->manages($user);
    }

    public function view(User $user, Plan $plan): bool
    {
        return $this->manages($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Plan $plan): bool
    {
        return $this->manages($user);
    }

    public function delete(User $user, Plan $plan): bool
    {
        return false;
    }
}
