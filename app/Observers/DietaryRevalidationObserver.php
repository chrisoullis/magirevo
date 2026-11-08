<?php

namespace App\Observers;

use App\Models\IngredientSubstitution;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeIngredientTranslation;
use App\Models\RecipeShare;
use App\Models\RecipeStep;
use App\Models\RecipeStepTranslation;
use App\Services\DietaryClaimService;
use App\Services\RecipeComponentInvalidationService;
use Illuminate\Database\Eloquent\Model;

/**
 * Watches only the records that feed the dietary fingerprint, so edits to titles, media or SEO text
 * never trigger revalidation. The fingerprint itself decides whether anything actually changed.
 */
class DietaryRevalidationObserver
{
    public function __construct(private DietaryClaimService $claims) {}

    public function saved(Model $model): void
    {
        $this->schedule($model);
    }

    public function deleted(Model $model): void
    {
        $this->schedule($model);
    }

    private function schedule(Model $model): void
    {
        $recipeId = match (true) {
            $model instanceof Recipe => $model->trashed() || $model->wasChanged(['status', 'visibility', 'published_at', 'deleted_at']) ? $model->getKey() : null,
            $model instanceof RecipeShare => $model->recipe_id,
            $model instanceof RecipeIngredient, $model instanceof RecipeStep, $model instanceof IngredientSubstitution => $model->recipe_id,
            $model instanceof RecipeIngredientTranslation => $model->recipeIngredient?->recipe_id,
            $model instanceof RecipeStepTranslation => $model->recipeStep?->recipe_id,
            default => null,
        };

        if ($recipeId !== null && Recipe::withTrashed()->whereKey($recipeId)->exists()) {
            app(RecipeComponentInvalidationService::class)->schedule((int) $recipeId, $model instanceof Recipe || $model instanceof RecipeShare);
            $this->claims->scheduleRevalidation(
                (int) $recipeId,
                $model instanceof Recipe
                    ? 'Recipe publication state changed; the approved revision was checked before publication.'
                    : 'Recipe ingredients, quantities, preparation instructions or confirmed substitutions changed.'
            );
        }
    }
}
