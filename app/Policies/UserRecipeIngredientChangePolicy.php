<?php

namespace App\Policies;

use App\Models\Recipe;
use App\Models\User;
use App\Models\UserRecipeIngredientChange;
use App\Services\RecipeAccessService;
use App\Support\AppPermissions;

class UserRecipeIngredientChangePolicy
{
    public function __construct(private RecipeAccessService $recipeAccess) {}

    public function view(User $user, UserRecipeIngredientChange $userRecipeIngredientChange): bool
    {
        return (int) $userRecipeIngredientChange->user_id === (int) $user->getKey()
            && $this->canPersonalize($user, $userRecipeIngredientChange->recipe);
    }

    public function create(User $user, Recipe $recipe): bool
    {
        return $this->canPersonalize($user, $recipe);
    }

    public function update(User $user, UserRecipeIngredientChange $userRecipeIngredientChange): bool
    {
        return $this->view($user, $userRecipeIngredientChange);
    }

    public function delete(User $user, UserRecipeIngredientChange $userRecipeIngredientChange): bool
    {
        return $this->view($user, $userRecipeIngredientChange);
    }

    private function canPersonalize(User $user, Recipe $recipe): bool
    {
        return $user->can(AppPermissions::RECIPES_PERSONALIZE)
            && $this->recipeAccess->canView($user, $recipe);
    }
}
