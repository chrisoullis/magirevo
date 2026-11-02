<?php

namespace App\Observers;

use App\Enums\RecipeRevisionStatus;
use App\Models\IngredientAllergenAssessment;
use App\Models\IngredientComponent;
use App\Models\IngredientDietaryAssessment;
use App\Models\IngredientDietaryProfile;
use App\Models\RecipeIngredient;
use App\Models\RecipeRevision;
use App\Services\DietaryClaimService;
use App\Support\AppPermissions;
use Illuminate\Database\Eloquent\Model;

class IngredientDietaryMetadataObserver
{
    public function __construct(private DietaryClaimService $claims) {}

    public function saving(Model $model): void
    {
        if (! $model instanceof IngredientDietaryProfile
            && ! $model instanceof IngredientDietaryAssessment
            && ! $model instanceof IngredientAllergenAssessment) {
            return;
        }

        $reviewer = auth()->user();

        if ($reviewer?->hasVerifiedEmail() && $reviewer->can(AppPermissions::DIETARY_CLAIMS_REVIEW)) {
            $model->forceFill([
                'reviewed_by' => $reviewer->getKey(),
                'reviewed_at' => now(),
            ]);
        }
    }

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
        $ingredientId = match (true) {
            $model instanceof IngredientComponent,
            $model instanceof IngredientDietaryProfile,
            $model instanceof IngredientDietaryAssessment,
            $model instanceof IngredientAllergenAssessment => (int) $model->ingredient_id,
            default => null,
        };

        if ($ingredientId === null) {
            return;
        }

        $affectedIngredientIds = [$ingredientId];
        $frontier = [$ingredientId];

        while ($frontier !== []) {
            $parents = IngredientComponent::query()
                ->whereIn('component_ingredient_id', $frontier)
                ->pluck('ingredient_id')
                ->map(fn ($id): int => (int) $id)
                ->diff($affectedIngredientIds)
                ->values()
                ->all();
            $affectedIngredientIds = array_values(array_unique([...$affectedIngredientIds, ...$parents]));
            $frontier = $parents;
        }

        // Live lines plus frozen in-review and published revisions, whose claims are checked against their payload.
        $revisionRecipeIds = RecipeRevision::query()
            ->whereIn('status', [RecipeRevisionStatus::InReview->value, RecipeRevisionStatus::Published->value, RecipeRevisionStatus::Superseded->value])
            ->where(function ($query) use ($affectedIngredientIds): void {
                foreach ($affectedIngredientIds as $id) {
                    $query->orWhereRaw('payload->\'ingredients\' @> ?::jsonb', [json_encode([['ingredient_id' => $id]])]);
                }
            })
            ->pluck('recipe_id');

        RecipeIngredient::query()
            ->whereIn('ingredient_id', $affectedIngredientIds)
            ->pluck('recipe_id')
            ->concat($revisionRecipeIds)
            ->unique()
            ->each(fn (int $recipeId) => $this->claims->scheduleRevalidation(
                $recipeId,
                'Ingredient composition, animal-origin, allergen or dietary verification metadata changed.'
            ));
    }
}
