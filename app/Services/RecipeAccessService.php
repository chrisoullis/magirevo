<?php

namespace App\Services;

use App\Enums\RecipeStatus;
use App\Enums\RecipeVisibility;
use App\Models\Recipe;
use App\Models\User;
use App\Support\AppPermissions;

/**
 * Reusable access rules for single recipes. Policies stay the authorization boundary and delegate here.
 */
class RecipeAccessService
{
    public function __construct(private AuditLogger $audit) {}

    public function owns(User $user, Recipe $recipe): bool
    {
        return $recipe->author_id !== null && (int) $recipe->author_id === (int) $user->getKey();
    }

    /**
     * Recipes in the shared editorial pool: public ones and submissions awaiting review.
     */
    public function isEditorial(Recipe $recipe): bool
    {
        return $recipe->visibility === RecipeVisibility::Public
            || $recipe->status === RecipeStatus::Pending->value;
    }

    public function hasActiveShare(User $user, Recipe $recipe): bool
    {
        // Grants are suspended, not deleted, while a recipe is not shared.
        return $recipe->visibility === RecipeVisibility::Shared
            && $recipe->shares()->active()->where('user_id', $user->getKey())->exists();
    }

    public function canModeratePrivate(User $user): bool
    {
        return $user->can(AppPermissions::RECIPES_MODERATE_PRIVATE);
    }

    /**
     * Staff with recipe management permissions: editorial recipes, or any recipe with moderation access.
     */
    public function canManage(User $user, Recipe $recipe, string $permission = 'Update:Recipe'): bool
    {
        if (! $user->can($permission)) {
            return false;
        }

        return $this->isEditorial($recipe) || $this->owns($user, $recipe) || $this->canModeratePrivate($user);
    }

    public function canView(?User $user, Recipe $recipe): bool
    {
        if ($recipe->isPubliclyAccessible()) {
            return true;
        }

        if ($user === null || ! $user->hasVerifiedEmail()) {
            return false;
        }

        return $this->owns($user, $recipe)
            || ($user->can(AppPermissions::RECIPES_VIEW_SHARED) && $this->hasActiveShare($user, $recipe))
            || $this->canManage($user, $recipe, 'View:Recipe');
    }

    /**
     * True when the user can see a recipe only because of moderation access to someone else's private content.
     */
    public function isModerationAccess(User $user, Recipe $recipe): bool
    {
        return ! $recipe->isPubliclyAccessible()
            && ! $this->owns($user, $recipe)
            && ! $this->isEditorial($recipe)
            && ! $this->hasActiveShare($user, $recipe)
            && $this->canModeratePrivate($user);
    }

    public function recordModerationAccess(?User $user, Recipe $recipe, string $channel): void
    {
        if ($user === null || ! $this->isModerationAccess($user, $recipe)) {
            return;
        }

        $this->audit->record('private_recipe_moderation_access', [
            'user_id' => $user->getKey(),
            'recipe_id' => $recipe->getKey(),
            'channel' => $channel,
        ]);
    }
}
