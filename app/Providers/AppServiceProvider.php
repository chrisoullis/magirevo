<?php

namespace App\Providers;

use App\Models\IngredientAllergenAssessment;
use App\Models\IngredientComponent;
use App\Models\IngredientDietaryAssessment;
use App\Models\IngredientDietaryProfile;
use App\Models\IngredientSubstitution;
use App\Models\Media;
use App\Models\MediaTranslation;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeIngredientTranslation;
use App\Models\RecipeStep;
use App\Models\RecipeStepTranslation;
use App\Observers\DietaryRevalidationObserver;
use App\Observers\IngredientDietaryMetadataObserver;
use App\Observers\VariationMediaEditObserver;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach ([Media::class, MediaTranslation::class] as $model) {
            $model::observe(VariationMediaEditObserver::class);
        }

        foreach ([Recipe::class, RecipeIngredient::class, RecipeStep::class, RecipeIngredientTranslation::class, RecipeStepTranslation::class, IngredientSubstitution::class] as $model) {
            $model::observe(DietaryRevalidationObserver::class);
        }

        foreach ([IngredientComponent::class, IngredientDietaryProfile::class, IngredientDietaryAssessment::class, IngredientAllergenAssessment::class] as $model) {
            $model::observe(IngredientDietaryMetadataObserver::class);
        }

        if (app()->environment('local')) {
            Vite::createAssetPathsUsing(fn (string $path): string => '/'.ltrim($path, '/'));
        }
    }
}
