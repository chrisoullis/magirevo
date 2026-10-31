<?php

namespace App\Policies;

use App\Models\Recipe;
use App\Models\User;

/**
 * Editors write recipes and submit them for review; only administrators publish, delete and archive.
 */
class RecipePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user, Recipe $recipe): bool
    {
        return $user->isStaff();
    }

    public function create(User $user): bool
    {
        return $user->isStaff();
    }

    public function update(User $user, Recipe $recipe): bool
    {
        return $user->isStaff();
    }

    public function delete(User $user, Recipe $recipe): bool
    {
        return $user->isAdministrator();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdministrator();
    }

    public function restore(User $user, Recipe $recipe): bool
    {
        return $user->isAdministrator();
    }

    public function restoreAny(User $user): bool
    {
        return $user->isAdministrator();
    }

    public function forceDelete(User $user, Recipe $recipe): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function submitForReview(User $user, Recipe $recipe): bool
    {
        return $user->isStaff();
    }

    public function publish(User $user, Recipe $recipe): bool
    {
        return $user->isAdministrator();
    }

    public function archive(User $user, Recipe $recipe): bool
    {
        return $user->isAdministrator();
    }
}
