<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\AppPermissions;
use Illuminate\Contracts\View\View;

class SharedWithMeController extends Controller
{
    public function __invoke(string $locale): View
    {
        $user = auth()->user();

        abort_unless($user->can(AppPermissions::RECIPES_VIEW_SHARED), 403);

        $recipes = $user->sharedRecipes()
            ->with(['translations', 'media.translations'])
            ->withPivot('shared_by')
            ->orderByDesc('recipe_shares.created_at')
            ->get();

        $sharers = User::query()->whereIn('id', $recipes->pluck('pivot.shared_by')->filter())->pluck('name', 'id');

        return view('recipes.shared', compact('recipes', 'sharers', 'locale'));
    }
}
