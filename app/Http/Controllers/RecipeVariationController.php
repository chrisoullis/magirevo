<?php

namespace App\Http\Controllers;

use App\Models\Recipe;
use Illuminate\Support\Facades\Gate;

class RecipeVariationController extends Controller
{
    public function index(string $locale)
    {
        abort_unless(config('recipe-variations.enabled') || Gate::allows('create', Recipe::class), 404);

        return response()->view('recipes.personal', ['locale' => $locale, 'alternateUrls' => collect(['en', 'el'])->mapWithKeys(fn ($language) => [$language => route('recipes.personal', ['locale' => $language], false)])->all()])
            ->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    public function edit(string $locale, Recipe $recipe)
    {
        abort_unless(config('recipe-variations.enabled'), 404);
        Gate::authorize('editVariation', $recipe);

        return response()->view('recipes.variation-edit', ['locale' => $locale, 'recipeId' => $recipe->id, 'alternateUrls' => collect(['en', 'el'])->mapWithKeys(fn ($language) => [$language => route('recipes.variations.edit', ['locale' => $language, 'recipe' => $recipe->id], false)])->all()])
            ->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }
}
