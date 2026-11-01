<?php

namespace App\Policies;

use App\Enums\HouseholdAbility;
use App\Models\MealPlanTemplate;
use App\Models\User;
use App\Services\HouseholdAccess;

/**
 * Personal templates belong to their creator; household templates follow the household role matrix.
 * Global administrators get no access to other people's templates.
 */
class MealPlanTemplatePolicy
{
    public function __construct(private HouseholdAccess $access) {}

    public function view(User $user, MealPlanTemplate $template): bool
    {
        return $this->allows($user, $template, HouseholdAbility::View);
    }

    public function update(User $user, MealPlanTemplate $template): bool
    {
        return $this->allows($user, $template, HouseholdAbility::EditPlans);
    }

    public function delete(User $user, MealPlanTemplate $template): bool
    {
        return $this->update($user, $template);
    }

    private function allows(User $user, MealPlanTemplate $template, HouseholdAbility $ability): bool
    {
        if ($template->isPersonal()) {
            return $this->access->canUsePlanner($user) && (int) $template->created_by === (int) $user->getKey();
        }

        $household = $template->household()->first();

        return $household !== null && $this->access->can($user, $household, $ability);
    }
}
