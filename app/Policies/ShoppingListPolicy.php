<?php

namespace App\Policies;

use App\Enums\HouseholdAbility;
use App\Models\ShoppingList;
use App\Models\User;
use App\Services\HouseholdAccess;

/**
 * A shopping list is visible to the people who can see its plan. Editing it needs the household shopping ability
 * (members and above), or being the owner of a personal plan. Global administrators get no access.
 */
class ShoppingListPolicy
{
    public function __construct(private HouseholdAccess $access) {}

    public function view(User $user, ShoppingList $list): bool
    {
        return $user->can('view', $list->mealPlan);
    }

    public function update(User $user, ShoppingList $list): bool
    {
        $plan = $list->mealPlan;

        if ($plan->isPersonal()) {
            return $user->can('view', $plan);
        }

        return $user->can('view', $plan) && $this->access->can($user, $plan->household, HouseholdAbility::EditShopping);
    }
}
