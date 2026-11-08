<?php

namespace App\Policies;

use App\Enums\RecipeStatus;
use App\Enums\RecipeVisibility;
use App\Models\Recipe;
use App\Models\User;
use App\Services\RecipeAccessService;
use App\Services\RecipeQuotaService;
use App\Services\Recipes\RecipeVariationPublishingService;
use App\Services\Recipes\RecipeVariationRights;
use App\Support\AppPermissions;

/**
 * Permissions say what a user may do in general; ownership, visibility, share grants and workflow
 * state decide whether they may do it to this particular recipe.
 */
class RecipePolicy
{
    public function __construct(private RecipeAccessService $access) {}

    private function verified(User $user): bool
    {
        return $user->hasVerifiedEmail();
    }

    private function ownsWith(User $user, Recipe $recipe, string $permission): bool
    {
        return $this->access->owns($user, $recipe) && $user->can($permission);
    }

    public function viewAny(User $user): bool
    {
        return $this->verified($user)
            && ($user->can('ViewAny:Recipe') || $user->can(AppPermissions::RECIPES_VIEW_OWNED));
    }

    public function view(User $user, Recipe $recipe): bool
    {
        return $this->access->canView($user, $recipe);
    }

    public function fork(User $user, Recipe $recipe): bool
    {
        return config('recipe-variations.enabled', false)
            && $this->verified($user)
            && $user->can(AppPermissions::RECIPES_FORK)
            && $this->access->canView($user, $recipe)
            && $recipe->isPubliclyAccessible()
            && $recipe->derived_from_recipe_id === null
            && $recipe->current_revision_id !== null
            && app(RecipeVariationRights::class)->permitsPrivate($recipe)
            && ! app(RecipeQuotaService::class)->hasReachedLimit($user);
    }

    public function editVariation(User $user, Recipe $recipe): bool
    {
        return config('recipe-variations.enabled', false)
            && $this->verified($user)
            && $user->can(AppPermissions::RECIPES_FORK)
            && $this->access->owns($user, $recipe)
            && ! $recipe->trashed()
            && $recipe->derived_from_recipe_id !== null
            && $recipe->derivation()->exists()
            && $recipe->status === RecipeStatus::Draft->value
            && $recipe->visibility === RecipeVisibility::Private
            && $recipe->published_at === null
            && $recipe->current_revision_id === null;
    }

    public function submitVariation(User $user, Recipe $recipe): bool
    {
        return config('recipe-variations.public_submissions_enabled', false)
            && $this->editVariation($user, $recipe)
            && app(RecipeVariationPublishingService::class)->eligible($user)
            && app(RecipeVariationPublishingService::class)->publicRights($recipe);
    }

    public function create(User $user): bool
    {
        return $this->verified($user)
            && ($user->can('Create:Recipe') || $user->can(AppPermissions::RECIPES_CREATE));
    }

    /**
     * Owners edit their own recipes. Content that is already public may only be changed by editorial staff,
     * so unreviewed edits can never appear on an approved public URL.
     */
    public function update(User $user, Recipe $recipe): bool
    {
        if (! $this->verified($user)) {
            return false;
        }

        if ($this->editVariation($user, $recipe)) {
            return true;
        }

        if ($recipe->derived_from_recipe_id !== null && $recipe->status === RecipeStatus::Pending->value) {
            return false;
        }

        // The submitter cannot change content a reviewer is looking at; they withdraw it first.
        if ($recipe->status === RecipeStatus::Pending->value && $this->access->owns($user, $recipe)) {
            return false;
        }

        if ($this->access->canManage($user, $recipe)) {
            return true;
        }

        return $recipe->visibility !== RecipeVisibility::Public
            && $this->ownsWith($user, $recipe, AppPermissions::RECIPES_UPDATE_OWNED);
    }

    public function delete(User $user, Recipe $recipe): bool
    {
        if (! $this->verified($user)) {
            return false;
        }

        return $this->access->canManage($user, $recipe, 'Delete:Recipe')
            || ($recipe->visibility !== RecipeVisibility::Public
                && $this->ownsWith($user, $recipe, AppPermissions::RECIPES_DELETE_OWNED));
    }

    public function deleteAny(User $user): bool
    {
        return $this->verified($user) && $user->can('DeleteAny:Recipe');
    }

    public function restore(User $user, Recipe $recipe): bool
    {
        return $this->verified($user) && $this->access->canManage($user, $recipe, 'Restore:Recipe');
    }

    public function restoreAny(User $user): bool
    {
        return $this->verified($user) && $user->can('RestoreAny:Recipe');
    }

    public function forceDelete(User $user, Recipe $recipe): bool
    {
        return $this->verified($user) && $user->can('ForceDelete:Recipe');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $this->verified($user) && $user->can('ForceDeleteAny:Recipe');
    }

    public function submitForReview(User $user, Recipe $recipe): bool
    {
        return $recipe->derived_from_recipe_id === null
            && $this->verified($user)
            && $user->can(AppPermissions::RECIPES_SUBMIT)
            && ! $recipe->trashed()
            && $this->submittable($recipe)
            && ($this->access->owns($user, $recipe) || $this->access->canManage($user, $recipe));
    }

    /**
     * Drafts, and recipes that were published once but are private now. Public recipes are never resubmitted in place.
     */
    private function submittable(Recipe $recipe): bool
    {
        return $recipe->status === RecipeStatus::Draft->value
            || ($recipe->status === RecipeStatus::Published->value && $recipe->visibility !== RecipeVisibility::Public);
    }

    /**
     * Owners take their recipe out of review, or off the public site, to edit it.
     */
    public function withdraw(User $user, Recipe $recipe): bool
    {
        return $this->verified($user)
            && ! $recipe->trashed()
            && in_array($recipe->status, [RecipeStatus::Pending->value, RecipeStatus::Published->value], true)
            && ($this->ownsWith($user, $recipe, AppPermissions::RECIPES_UPDATE_OWNED) || ($recipe->derived_from_recipe_id !== null && $recipe->author_id === $user->id && $user->can(AppPermissions::RECIPES_FORK)));
    }

    /**
     * Reviewers may not approve their own submissions.
     */
    public function approve(User $user, Recipe $recipe): bool
    {
        return $this->canReview($user, $recipe);
    }

    public function reject(User $user, Recipe $recipe): bool
    {
        return $this->canReview($user, $recipe);
    }

    private function canReview(User $user, Recipe $recipe): bool
    {
        return $recipe->derived_from_recipe_id === null
            && $this->verified($user)
            && $user->can(AppPermissions::RECIPES_REVIEW)
            && ! $recipe->trashed()
            && $recipe->status === RecipeStatus::Pending->value
            && ! $this->access->owns($user, $recipe);
    }

    public function publish(User $user, Recipe $recipe): bool
    {
        return $recipe->derived_from_recipe_id === null
            && $this->verified($user)
            && $user->can(AppPermissions::RECIPES_PUBLISH)
            && $this->access->canManage($user, $recipe);
    }

    public function archive(User $user, Recipe $recipe): bool
    {
        return $this->publish($user, $recipe);
    }

    /**
     * Owners may make their recipe private or shared again at any time; making it public always needs publish rights.
     */
    public function changeVisibility(User $user, Recipe $recipe, ?RecipeVisibility $to = null): bool
    {
        if (! $this->verified($user)) {
            return false;
        }

        if ($recipe->derived_from_recipe_id !== null && $to !== RecipeVisibility::Private) {
            return false;
        }

        if ($to === RecipeVisibility::Public) {
            return $this->publish($user, $recipe);
        }

        if ($to === RecipeVisibility::Shared && ! $user->can(AppPermissions::RECIPES_SHARE)) {
            return false;
        }

        // Sharing is a distinct permission: an owner allowed to grant named access need not also
        // have permission to edit recipe content just to switch the visibility to "shared".
        if ($to === RecipeVisibility::Shared) {
            return $this->share($user, $recipe);
        }

        return $this->access->canManage($user, $recipe)
            || $this->ownsWith($user, $recipe, AppPermissions::RECIPES_UPDATE_OWNED);
    }

    public function share(User $user, Recipe $recipe): bool
    {
        return $recipe->derived_from_recipe_id === null
            && $this->verified($user)
            && ! $recipe->trashed()
            && $this->ownsWith($user, $recipe, AppPermissions::RECIPES_SHARE);
    }

    public function revokeShare(User $user, Recipe $recipe): bool
    {
        return $this->share($user, $recipe);
    }
}
