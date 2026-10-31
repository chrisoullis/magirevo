<?php

namespace App\Policies;

use App\Models\Recipe;
use App\Models\User;

class RecipePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdministrator($user);
    }

    public function view(User $user, Recipe $recipe): bool
    {
        return $this->isAdministrator($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdministrator($user);
    }

    public function update(User $user, Recipe $recipe): bool
    {
        return $this->isAdministrator($user);
    }

    public function delete(User $user, Recipe $recipe): bool
    {
        return $this->isAdministrator($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->isAdministrator($user);
    }

    public function restore(User $user, Recipe $recipe): bool
    {
        return $this->isAdministrator($user);
    }

    public function restoreAny(User $user): bool
    {
        return $this->isAdministrator($user);
    }

    public function forceDelete(User $user, Recipe $recipe): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function publish(User $user, Recipe $recipe): bool
    {
        return $this->isAdministrator($user);
    }

    private function isAdministrator(User $user): bool
    {
        return $user->is_admin && $user->hasVerifiedEmail();
    }
}
