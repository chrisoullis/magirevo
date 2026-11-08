<?php

namespace App\Http\Controllers;

use App\Services\MyKitchenData;
use App\Support\PublicNavigation;
use Illuminate\Http\Response;

class MyKitchenController extends Controller
{
    public function __invoke(string $locale, MyKitchenData $data): Response
    {
        return response()->view('kitchen.index', [...$data->sections(auth()->user(), $locale), 'createUrl' => app(PublicNavigation::class)->links(auth()->user(), $locale)['createUrl']])
            ->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }
}
