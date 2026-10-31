<?php

namespace App\Http\Controllers;

use App\Models\Recipe;
use Illuminate\Http\RedirectResponse;

class SaveRecipeController extends Controller
{
    public function __invoke(string $locale, Recipe $recipe): RedirectResponse
    {
        abort_unless(
            Recipe::query()->published()->whereKey($recipe->getKey())->exists(),
            404,
        );

        auth()->user()->favoriteRecipes()->toggle($recipe->id);

        return back()->with('status', 'Your recipe box has been updated.');
    }
}
