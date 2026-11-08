<?php

namespace App\Http\Controllers;

use App\Models\Recipe;
use App\Models\RecipeRevision;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class ContributorRecipeStudioController extends Controller
{
    public function create(string $locale): Response
    {
        Gate::authorize('create', Recipe::class);

        return $this->page($locale, null);
    }

    public function edit(string $locale, Recipe $recipe): Response
    {
        abort_unless($recipe->author_id === auth()->id() && $recipe->derived_from_recipe_id === null, 404);
        Gate::authorize('create', Recipe::class);
        if (! Gate::allows('update', $recipe) && ! Gate::allows('createDraft', [RecipeRevision::class, $recipe])) {
            Gate::authorize('view', $recipe);
        }

        return $this->page($locale, $recipe->id);
    }

    private function page(string $locale, ?int $recipeId): Response
    {
        return response()->view('recipes.studio', compact('locale', 'recipeId'))
            ->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }
}
