<?php

namespace App\Http\Controllers;

use App\Models\Ingredient;
use App\Models\Recipe;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function __invoke(): Response
    {
        return response()->view('sitemap', [
            'locales' => config('translatable.locales'),
            'recipes' => Recipe::query()->published()->with('translations')->orderBy('id')->get(),
            'ingredients' => Ingredient::query()->where(fn ($query) => $query
                ->where(fn ($en) => $en->publiclyVisible('en'))->orWhere(fn ($el) => $el->publiclyVisible('el')))
                ->with(['translations', 'catalogGroup.translations'])->orderBy('id')->get(),
        ])->header('Content-Type', 'application/xml');
    }
}
