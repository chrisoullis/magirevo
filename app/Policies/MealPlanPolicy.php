<?php

namespace App\Policies;

use App\Enums\HouseholdAbility;
use App\Models\Household;
use App\Models\MealPlan;
use App\Models\User;
use App\Services\HouseholdAccess;

/**
 * Personal plans belong to their creator. Household plans follow the household role matrix.
 * There is no before() hook: global administrators get no access to other people's plans.
 */
class MealPlanPolicy
{
    public function __construct(private HouseholdAccess $access) {}

    public function view(User $user, MealPlan $plan): bool
    {
        return $this->allows($user, $plan, HouseholdAbility::View);
    }

    public function update(User $user, MealPlan $plan): bool
    {
        return $this->allows($user, $plan, HouseholdAbility::EditPlans);
    }

    public function delete(User $user, MealPlan $plan): bool
    {
        return $this->update($user, $plan);
    }

    private function allows(User $user, MealPlan $plan, HouseholdAbility $ability): bool
    {
        if ($plan->isPersonal()) {
            return $this->access->canUsePlanner($user) && (int) $plan->created_by === (int) $user->getKey();
        }

        $household = $plan->relationLoaded('household') ? $plan->household : Household::query()->find($plan->household_id);

        return $household !== null && $this->access->can($user, $household, $ability);
    }
}
