<?php

namespace App\Policies;

use App\Enums\HouseholdAbility;
use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\User;
use App\Services\HouseholdAccess;
use App\Support\AppPermissions;

/**
 * There is deliberately no before() hook: administrators get no access to other people's households.
 */
class HouseholdPolicy
{
    public function __construct(private HouseholdAccess $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->canUsePlanner($user);
    }

    public function create(User $user): bool
    {
        return $this->access->canUsePlanner($user) && $user->can(AppPermissions::HOUSEHOLDS_CREATE);
    }

    public function view(User $user, Household $household): bool
    {
        return $this->access->can($user, $household, HouseholdAbility::View);
    }

    public function update(User $user, Household $household): bool
    {
        return $this->access->can($user, $household, HouseholdAbility::Update);
    }

    public function manageMembers(User $user, Household $household): bool
    {
        return $this->access->can($user, $household, HouseholdAbility::ManageMembers);
    }

    public function manageInvitations(User $user, Household $household): bool
    {
        return $this->access->can($user, $household, HouseholdAbility::ManageInvitations);
    }

    public function transferOwnership(User $user, Household $household): bool
    {
        return $this->access->can($user, $household, HouseholdAbility::TransferOwnership);
    }

    public function delete(User $user, Household $household): bool
    {
        return $this->access->can($user, $household, HouseholdAbility::Delete);
    }

    /**
     * Any member may leave except the owner, who must transfer ownership or delete the household first.
     */
    public function leave(User $user, Household $household): bool
    {
        return $this->access->canUsePlanner($user)
            && ($role = $this->access->roleOf($user, $household)) !== null
            && $role !== HouseholdRole::Owner;
    }
}
