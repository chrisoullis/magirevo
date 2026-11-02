<?php

namespace App\Policies;

use App\Enums\RecipeRevisionStatus;
use App\Models\Recipe;
use App\Models\RecipeRevision;
use App\Models\User;
use App\Services\RecipeAccessService;
use App\Services\RecipeRevisionService;
use App\Support\AppPermissions;

/**
 * Who may propose, edit, review, publish and roll back revisions. Reviewers can never approve a revision they
 * created, edited or submitted.
 */
class RecipeRevisionPolicy
{
    public function __construct(private RecipeAccessService $access, private RecipeRevisionService $revisions) {}

    private function verified(User $user): bool
    {
        return $user->hasVerifiedEmail();
    }

    public function viewAny(User $user): bool
    {
        return $this->verified($user)
            && ($user->can(AppPermissions::REVISIONS_VIEW_HISTORY) || $user->can(AppPermissions::RECIPES_UPDATE_OWNED));
    }

    public function view(User $user, RecipeRevision $revision): bool
    {
        return $this->viewHistory($user, $revision->recipe);
    }

    public function update(User $user, RecipeRevision $revision): bool
    {
        return $this->edit($user, $revision);
    }

    public function viewHistory(User $user, Recipe $recipe): bool
    {
        return $this->verified($user)
            && ($user->can(AppPermissions::REVISIONS_VIEW_HISTORY) || $this->access->owns($user, $recipe))
            && ($this->access->owns($user, $recipe) || $this->access->canManage($user, $recipe));
    }

    public function createDraft(User $user, Recipe $recipe): bool
    {
        return $this->verified($user)
            && ! $recipe->trashed()
            && $this->revisions->isLive($recipe)
            && ($this->access->canManage($user, $recipe)
                || ($this->access->owns($user, $recipe) && $user->can(AppPermissions::RECIPES_UPDATE_OWNED)));
    }

    /**
     * Only the revision's creator, or staff who can manage the recipe, edit a draft; nobody edits it after submission.
     */
    public function edit(User $user, RecipeRevision $revision): bool
    {
        return $revision->status === RecipeRevisionStatus::Draft
            && $this->verified($user)
            && $this->createDraft($user, $revision->recipe)
            && ((int) $revision->created_by === (int) $user->getKey() || $this->access->canManage($user, $revision->recipe));
    }

    public function submit(User $user, RecipeRevision $revision): bool
    {
        return $this->edit($user, $revision) && $user->can(AppPermissions::RECIPES_SUBMIT);
    }

    public function discard(User $user, RecipeRevision $revision): bool
    {
        return $this->edit($user, $revision);
    }

    public function approve(User $user, RecipeRevision $revision): bool
    {
        return $revision->status === RecipeRevisionStatus::InReview
            && $this->verified($user)
            && $user->can(AppPermissions::RECIPES_REVIEW)
            && ! $revision->recipe->trashed()
            && ! $this->revisions->isInvolved($revision, $user)
            && ! $this->access->owns($user, $revision->recipe);
    }

    public function reject(User $user, RecipeRevision $revision): bool
    {
        return $this->approve($user, $revision);
    }

    /**
     * A new draft from a rejected revision continues the author's own work.
     */
    public function reviseRejected(User $user, RecipeRevision $revision): bool
    {
        return $revision->status === RecipeRevisionStatus::Rejected && $this->createDraft($user, $revision->recipe);
    }

    public function rollback(User $user, RecipeRevision $revision): bool
    {
        return in_array($revision->status, [RecipeRevisionStatus::Superseded, RecipeRevisionStatus::Published], true)
            && $this->verified($user)
            && $user->can(AppPermissions::REVISIONS_ROLLBACK)
            && $user->can(AppPermissions::RECIPES_PUBLISH)
            && $this->access->canManage($user, $revision->recipe)
            && $this->revisions->isLive($revision->recipe);
    }
}
