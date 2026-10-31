<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class SavedRecipesController extends Controller
{
    public function __invoke(): View
    {
        $recipes = auth()->user()->favoriteRecipes()
            ->published()
            ->with(['translations', 'categories.translations', 'media.translations'])
            ->orderByDesc('recipe_favorites.created_at')
            ->get();

        return view('recipes.saved', compact('recipes'));
    }
}
