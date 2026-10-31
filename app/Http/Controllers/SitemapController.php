<?php

namespace App\Http\Controllers;

use App\Models\Recipe;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function __invoke(): Response
    {
        return response()->view('sitemap', [
            'locales' => config('translatable.locales'),
            'recipes' => Recipe::query()->published()->with('translations')->orderBy('id')->get(),
        ])->header('Content-Type', 'application/xml');
    }
}
