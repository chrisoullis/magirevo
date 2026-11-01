<?php

namespace App\Policies;

use App\Enums\HouseholdAbility;
use App\Models\BatchCook;
use App\Models\User;
use App\Services\HouseholdAccess;

/**
 * Personal batches belong to their creator; household batches follow the household role matrix
 * (viewers read, members and above plan and record consumption). Global administrators get no access.
 */
class BatchCookPolicy
{
    public function __construct(private HouseholdAccess $access) {}

    public function view(User $user, BatchCook $batch): bool
    {
        return $this->allows($user, $batch, HouseholdAbility::View);
    }

    public function update(User $user, BatchCook $batch): bool
    {
        return $this->allows($user, $batch, HouseholdAbility::EditPlans);
    }

    public function consume(User $user, BatchCook $batch): bool
    {
        return $this->allows($user, $batch, HouseholdAbility::ConsumeBatches);
    }

    private function allows(User $user, BatchCook $batch, HouseholdAbility $ability): bool
    {
        if ($batch->isPersonal()) {
            return $this->access->canUsePlanner($user) && (int) $batch->created_by === (int) $user->getKey();
        }

        $household = $batch->household()->first();

        return $household !== null && $this->access->can($user, $household, $ability);
    }
}
