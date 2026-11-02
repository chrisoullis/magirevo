<?php

namespace App\Policies;

use App\Models\Recipe;
use App\Models\User;
use App\Models\UserRecipeNote;
use App\Services\RecipeAccessService;
use App\Support\AppPermissions;

class UserRecipeNotePolicy
{
    public function __construct(private RecipeAccessService $recipeAccess) {}

    public function viewAny(User $user, Recipe $recipe): bool
    {
        return $user->can(AppPermissions::RECIPES_PERSONALIZE)
            && $this->recipeAccess->canView($user, $recipe);
    }

    public function view(User $user, UserRecipeNote $userRecipeNote): bool
    {
        return (int) $userRecipeNote->user_id === (int) $user->getKey()
            && $this->viewAny($user, $userRecipeNote->recipe);
    }

    public function create(User $user, Recipe $recipe): bool
    {
        return $this->viewAny($user, $recipe);
    }

    public function update(User $user, UserRecipeNote $userRecipeNote): bool
    {
        return $this->view($user, $userRecipeNote);
    }

    public function delete(User $user, UserRecipeNote $userRecipeNote): bool
    {
        return $this->view($user, $userRecipeNote);
    }
}
