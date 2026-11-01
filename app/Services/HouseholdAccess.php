<?php

namespace App\Services;

use App\Enums\HouseholdAbility;
use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;
use App\Support\AppPermissions;

/**
 * The one place that answers "what may this user do in this household". Global permission and household role
 * are both required: neither an administrator role nor a household role ever stands in for the other.
 */
class HouseholdAccess
{
    public function canUsePlanner(User $user): bool
    {
        return $user->hasVerifiedEmail() && $user->can(AppPermissions::PLANNER_USE);
    }

    public function roleOf(User $user, Household $household): ?HouseholdRole
    {
        return HouseholdMember::query()
            ->where('household_id', $household->getKey())
            ->where('user_id', $user->getKey())
            ->first()?->role;
    }

    public function can(User $user, Household $household, HouseholdAbility $ability): bool
    {
        if (! $this->canUsePlanner($user)) {
            return false;
        }

        return $this->roleOf($user, $household)?->can($ability) ?? false;
    }
}
