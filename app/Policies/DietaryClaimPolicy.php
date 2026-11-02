<?php

namespace App\Policies;

use App\Enums\DietaryClaimStatus;
use App\Models\DietaryClaim;
use App\Models\Recipe;
use App\Models\User;
use App\Services\DietaryClaimVisibility;
use App\Services\RecipeAccessService;
use App\Support\AppPermissions;

/**
 * Permission keys answer "may this kind of user do this"; the record-level rules here add that nobody
 * approves a claim they suggested and that high-risk claims need the dedicated high-risk permission.
 */
class DietaryClaimPolicy
{
    public function __construct(private RecipeAccessService $access, private DietaryClaimVisibility $visibility) {}

    public function viewAny(User $user): bool
    {
        return $this->can($user, AppPermissions::DIETARY_CLAIMS_VIEW);
    }

    public function view(User $user, DietaryClaim $claim): bool
    {
        $recipe = $claim->recipe;

        return $recipe !== null
            && $this->access->canView($user, $recipe)
            && ($this->can($user, AppPermissions::DIETARY_CLAIMS_VIEW)
                || ($this->can($user, AppPermissions::DIETARY_CLAIMS_SUGGEST) && (int) $claim->suggested_by === (int) $user->getKey()));
    }

    public function suggest(User $user, Recipe $recipe): bool
    {
        return $this->can($user, AppPermissions::DIETARY_CLAIMS_SUGGEST)
            && ($this->access->owns($user, $recipe) || $this->access->canManage($user, $recipe));
    }

    public function approve(User $user, DietaryClaim $claim): bool
    {
        return $this->canDecide($user, $claim)
            && ($claim->suggested_by === null || (int) $claim->suggested_by !== (int) $user->getKey());
    }

    public function reject(User $user, DietaryClaim $claim): bool
    {
        return $this->canDecide($user, $claim);
    }

    public function suspend(User $user, DietaryClaim $claim): bool
    {
        return $this->can($user, AppPermissions::DIETARY_CLAIMS_SUSPEND);
    }

    public function flag(User $user, DietaryClaim $claim): bool
    {
        $recipe = $claim->recipe;

        return $this->can($user, AppPermissions::DIETARY_CLAIMS_FLAG)
            && $recipe !== null
            && $this->access->canView($user, $recipe);
    }

    public function report(User $user, DietaryClaim $claim): bool
    {
        $recipe = $claim->recipe;

        return $user->hasVerifiedEmail()
            && $recipe !== null
            && $claim->status === DietaryClaimStatus::Approved
            && $recipe->isPubliclyAccessible()
            && $this->visibility->isCurrent($claim)
            && $this->access->canView($user, $recipe);
    }

    public function viewAudit(User $user): bool
    {
        return $this->can($user, AppPermissions::DIETARY_CLAIMS_VIEW_AUDIT);
    }

    private function canDecide(User $user, DietaryClaim $claim): bool
    {
        if (! $this->can($user, AppPermissions::DIETARY_CLAIMS_REVIEW)) {
            return false;
        }

        return ! $claim->isHighRisk() || $this->can($user, AppPermissions::DIETARY_CLAIMS_REVIEW_HIGH_RISK);
    }

    private function can(User $user, string $permission): bool
    {
        return $user->hasVerifiedEmail() && $user->can($permission);
    }
}
