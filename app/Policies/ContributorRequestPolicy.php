<?php

namespace App\Policies;

use App\Models\ContributorRequest;
use App\Models\User;
use App\Support\AppPermissions;

class ContributorRequestPolicy
{
    private function reviewer(User $user): bool
    {
        return $user->hasVerifiedEmail() && $user->can(AppPermissions::CONTRIBUTORS_REVIEW);
    }

    public function viewAny(User $user): bool
    {
        return $this->reviewer($user);
    }

    public function view(User $user, ContributorRequest $request): bool
    {
        return $this->reviewer($user) || $request->user_id === $user->getKey();
    }

    /**
     * Members apply once verified. People who already contribute (or hold a higher role) have no need to.
     */
    public function create(User $user): bool
    {
        return $user->hasVerifiedEmail()
            && $user->can(AppPermissions::CONTRIBUTORS_REQUEST)
            && ! $user->can(AppPermissions::RECIPES_CREATE);
    }

    public function cancel(User $user, ContributorRequest $request): bool
    {
        return $request->user_id === $user->getKey() && $request->isPending();
    }

    /**
     * Reviewers can never decide their own application.
     */
    public function approve(User $user, ContributorRequest $request): bool
    {
        return $this->reviewer($user) && $request->user_id !== $user->getKey();
    }

    public function reject(User $user, ContributorRequest $request): bool
    {
        return $this->approve($user, $request);
    }

    public function revoke(User $user, ContributorRequest $request): bool
    {
        return $this->approve($user, $request);
    }
}
