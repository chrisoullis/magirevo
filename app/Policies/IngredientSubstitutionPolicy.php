<?php

namespace App\Policies;

use App\Models\IngredientSubstitution;
use App\Models\User;

class IngredientSubstitutionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:Ingredient');
    }

    public function view(User $user, IngredientSubstitution $ingredientSubstitution): bool
    {
        return $user->can('View:Ingredient');
    }

    public function create(User $user): bool
    {
        return $user->can('Create:Ingredient');
    }

    public function update(User $user, IngredientSubstitution $ingredientSubstitution): bool
    {
        return $user->can('Update:Ingredient');
    }

    public function delete(User $user, IngredientSubstitution $ingredientSubstitution): bool
    {
        return $user->can('Delete:Ingredient');
    }
}
