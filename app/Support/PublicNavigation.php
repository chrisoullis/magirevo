<?php

namespace App\Support;

use App\Models\ContributorRequest;
use App\Models\Household;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/** Public destinations reflect existing routes and policies; navigation never grants access. */
class PublicNavigation
{
    public function links(?User $user, string $locale): array
    {
        $link = fn (string $route, string $label, string $icon, array $extra = [], ?bool $active = null): array => [
            'url' => route($route, ['locale' => $locale, ...$extra], false),
            'label' => __($label), 'icon' => $icon,
            'active' => $active ?? request()->routeIs($route),
        ];
        $recipes = $link('home.localized', 'site.nav_recipes', 'book', active: request()->routeIs('home.localized', 'recipes.show', 'categories.show'));
        $recipes['url'] .= '#recipes';
        $primary = [
            $recipes,
            $link('ingredients.index', 'ingredients.nav', 'leaf', active: request()->routeIs('ingredients.*')),
            $link('recipes.what-can-i-cook', 'site.what_can_i_cook', 'sparkles'),
        ];
        $plannerAllowed = $user === null || ($user->hasVerifiedEmail() && Gate::forUser($user)->allows('viewAny', Household::class));
        if ($plannerAllowed) {
            $primary[] = $link('planner.index', 'navigation.meal_planner', 'calendar', active: request()->routeIs('planner.*', 'households.planner', 'households.templates', 'households.batches', 'households.shopping'));
        }
        $kitchen = [];
        $createUrl = null;
        if ($user?->hasVerifiedEmail()) {
            if (Route::has('my-kitchen.index')) {
                $kitchen[] = $link('my-kitchen.index', 'navigation.dashboard', 'home');
            }
            $kitchen[] = $link('recipes.saved', 'site.recipe_box', 'bookmark');
            if ($user->can(AppPermissions::RECIPES_VIEW_SHARED)) {
                $kitchen[] = $link('recipes.shared', 'sharing.nav', 'users');
            }
            if (config('recipe-variations.enabled') || Gate::forUser($user)->allows('create', Recipe::class)) {
                $kitchen[] = $link('recipes.personal', 'navigation.my_recipes', 'book', ['filter' => 'original'], request()->routeIs('recipes.personal') && request('filter', 'all') !== 'variation');
            }
            if (config('recipe-variations.enabled')) {
                $kitchen[] = $link('recipes.personal', 'navigation.my_variations', 'sparkles', ['filter' => 'variation'], request()->routeIs('recipes.variations.edit') || (request()->routeIs('recipes.personal') && request('filter') === 'variation'));
            }
            if ($plannerAllowed) {
                $kitchen[] = $link('households.index', 'households.nav', 'users', active: request()->routeIs('households.index', 'households.show', 'households.invitations.show'));
                $kitchen[] = $link('planner.shopping', 'navigation.shopping_lists', 'shopping');
            }
            if (Gate::forUser($user)->allows('create', ContributorRequest::class)) {
                $kitchen[] = $link('contributor.show', 'navigation.become_contributor', 'plus');
            }
            if (Gate::forUser($user)->allows('create', Recipe::class)) {
                if (Route::has('recipes.create')) {
                    $createUrl = route('recipes.create', ['locale' => $locale], false);
                } elseif ($user->isStaff()) {
                    $createUrl = route('filament.admin.resources.recipes.create', [], false);
                }
            }
        }

        return compact('primary', 'kitchen', 'createUrl', 'plannerAllowed');
    }
}
