<?php

namespace App\Support;

use Illuminate\Support\Arr;

/** Resolve translated slugs supplied by controllers, or retain the current localized route. */
class PublicLocaleUrls
{
    public function resolve(string $locale, array $alternateUrls = [], array $switchUrls = []): string
    {
        $url = $switchUrls[$locale] ?? $alternateUrls[$locale] ?? null;
        $route = request()->route();
        if ($url === null && $route?->getName() && array_key_exists('locale', $route->parameters()) && ! array_key_exists('slug', $route->parameters())) {
            $url = route($route->getName(), [...Arr::only($route->parameters(), $route->parameterNames()), 'locale' => $locale], false);
        }
        $url ??= route('home.localized', ['locale' => $locale], false);
        $query = Arr::only(request()->query(), [
            'q', 'search', 'category', 'tag', 'difficulty', 'time', 'sort', 'group',
            'filter', 'collection', 'letter', 'ingredients', 'cuisine', 'method', 'dietary', 'rating', 'week', 'date', 'ingredient', 'servings', 'page',
            'recipes_page', 'substitutions_page', 'photos_page',
        ]);

        if (request()->routeIs('ingredients.index') && isset($query['letter']) && ! in_array($query['letter'], IngredientAlphabet::letters($locale), true)) {
            unset($query['letter'], $query['page']);
        }

        return $url.($query === [] ? '' : (str_contains($url, '?') ? '&' : '?').http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }
}
