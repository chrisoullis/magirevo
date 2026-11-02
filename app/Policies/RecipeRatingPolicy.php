<?php

namespace App\Policies;

use App\Models\Recipe;
use App\Models\RecipeRating;
use App\Models\User;
use App\Support\AppPermissions;

class RecipeRatingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(AppPermissions::RECIPES_REVIEW);
    }

    public function view(User $user, RecipeRating $recipeRating): bool
    {
        return $this->canModerate($user, $recipeRating);
    }

    public function create(User $user, Recipe $recipe): bool
    {
        return $user->can(AppPermissions::RECIPES_RATE)
            && $recipe->isPubliclyAccessible()
            && (int) $recipe->author_id !== (int) $user->getKey();
    }

    public function update(User $user, RecipeRating $recipeRating): bool
    {
        return (int) $recipeRating->user_id === (int) $user->getKey()
            && $recipeRating->rating_status === 'active'
            && $this->create($user, $recipeRating->recipe);
    }

    public function report(User $user, RecipeRating $recipeRating): bool
    {
        return $user->can(AppPermissions::RECIPES_RATE)
            && $recipeRating->rating_status === 'active'
            && $recipeRating->review_status === 'published'
            && filled($recipeRating->review_text)
            && (int) $recipeRating->user_id !== (int) $user->getKey()
            && $recipeRating->recipe->isPubliclyAccessible()
            && ! $recipeRating->reports()->where('reporter_id', $user->getKey())->exists();
    }

    public function moderateReview(User $user, RecipeRating $recipeRating): bool
    {
        return $this->canModerate($user, $recipeRating)
            && $recipeRating->rating_status === 'active'
            && filled($recipeRating->review_text);
    }

    public function moderateReports(User $user, RecipeRating $recipeRating): bool
    {
        return $this->canModerate($user, $recipeRating);
    }

    public function remove(User $user, RecipeRating $recipeRating): bool
    {
        return $this->canModerate($user, $recipeRating)
            && $recipeRating->rating_status === 'active'
            && $recipeRating->reports()->where('status', 'pending')->exists();
    }

    private function canModerate(User $user, RecipeRating $recipeRating): bool
    {
        return $user->can(AppPermissions::RECIPES_REVIEW)
            && $recipeRating->recipe->isPubliclyAccessible();
    }
}
