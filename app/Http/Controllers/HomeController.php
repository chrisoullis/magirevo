<?php

namespace App\Http\Controllers;

use App\Http\Requests\PublicRecipeFiltersRequest;
use App\Services\HomepageData;
use Illuminate\Http\Response;

class HomeController extends Controller
{
    public function __invoke(PublicRecipeFiltersRequest $request, string $locale, HomepageData $data): Response
    {
        return response()->view('home', $data->sections($locale, auth()->user()))
            ->header('Cache-Control', 'private, no-store')->header('Vary', 'Cookie');
    }
}
