<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class SavedRecipesController extends Controller
{
    public function __invoke(): View
    {
        $recipes = auth()->user()->savedRecipes()
            ->published()
            ->withTranslation(app()->getLocale())
            ->orderByDesc('recipe_user.created_at')
            ->get();

        return view('recipes.saved', compact('recipes'));
    }
}
