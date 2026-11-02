<?php

namespace App\Http\Controllers;

use App\Models\Media;
use App\Models\Recipe;
use App\Services\RecipeAccessService;
use App\Services\RecipeContentPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class RecipeController extends Controller
{
    public function show(string $locale, string $slug, RecipeContentPresenter $presenter, RecipeAccessService $access): Response
    {
        $recipe = $this->findViewable($locale, $slug, $access);

        $isPublic = $recipe->isPubliclyAccessible();

        if (! $isPublic) {
            $access->recordModerationAccess(Auth::user(), $recipe, 'site');
        }

        $category = $recipe->primaryCategory();

        $related = Recipe::query()
            ->published()
            ->whereKeyNot($recipe->getKey())
            ->when($category, fn ($query) => $query->inCategory($category->code))
            ->with(['translations', 'categories.translations', 'media.translations'])
            ->orderByDesc('is_featured')
            ->orderByDesc('published_at')
            ->limit(3)
            ->get();

        // A private or shared recipe may exist in one language only; the switcher then keeps the available one.
        $alternateUrls = collect(config('translatable.locales'))
            ->mapWithKeys(fn (string $alternate): array => [
                $alternate => route('recipes.show', [
                    'locale' => filled($recipe->getTranslation($alternate, false)?->slug) ? $alternate : $locale,
                    'slug' => $recipe->getTranslation($alternate, false)?->slug ?: $slug,
                ]),
            ])
            ->all();

        $ratingSummary = $isPublic ? $this->ratingSummary($recipe) : ['average' => null, 'count' => 0];

        $response = response()->view('recipes.show', [
            'isPublic' => $isPublic,
            'locale' => $locale,
            'recipe' => $recipe,
            'category' => $category,
            'related' => $related,
            'steps' => $presenter->steps($recipe, $locale),
            'baseServings' => $presenter->baseServings($recipe),
            'gallery' => $recipe->media
                ->filter(fn (Media $media): bool => $media->pivot->role === 'gallery' && $media->media_type === Media::TYPE_IMAGE)
                ->values(),
            'alternateUrls' => $alternateUrls,
            'structuredData' => $isPublic ? $this->structuredData(
                $recipe,
                $presenter,
                $locale,
                $alternateUrls[$locale],
                $category,
                $ratingSummary,
            ) : [],
        ]);

        if (! $isPublic) {
            $response->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
        }

        if (! $isPublic || Auth::check()) {
            $response->header('Cache-Control', 'private, no-store');
        }

        return $response;
    }

    public function print(Request $request, string $locale, string $slug, RecipeContentPresenter $presenter, RecipeAccessService $access): Response
    {
        $recipe = $this->findViewable($locale, $slug, $access);

        if (! $recipe->isPubliclyAccessible()) {
            $access->recordModerationAccess(Auth::user(), $recipe, 'site');
        }

        $base = $presenter->baseServings($recipe);
        $requested = $request->query('servings');
        $servings = is_numeric($requested) ? max(1, min(48, (int) $requested)) : $base;

        $response = response()->view('recipes.print', [
            'locale' => $locale,
            'recipe' => $recipe,
            'cover' => $recipe->coverMedia(),
            'servings' => $servings,
            'baseServings' => $base,
            'groups' => $presenter->ingredientGroups($recipe, (string) $servings),
            'steps' => $presenter->steps($recipe, $locale),
            'backUrl' => route('recipes.show', ['locale' => $locale, 'slug' => $slug], false),
        ]);

        // The print view is never indexed; non-public recipes must not be cached either.
        $response->header('X-Robots-Tag', 'noindex, nofollow, noarchive');

        if (! $recipe->isPubliclyAccessible()) {
            $response->header('Cache-Control', 'private, no-store');
        }

        return $response;
    }

    private function findViewable(string $locale, string $slug, RecipeAccessService $access): Recipe
    {
        $recipe = Recipe::query()
            ->with(['translations', 'categories.translations', 'tags.translations', 'media.translations', 'steps.translations', 'steps.media.translations', 'author'])
            ->whereTranslation('slug', $slug, $locale)
            ->first();

        // 404 rather than 403 so private and shared recipes are indistinguishable from missing ones.
        abort_if($recipe === null || ! $access->canView(Auth::user(), $recipe), 404);

        return $recipe;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function structuredData(
        Recipe $recipe,
        RecipeContentPresenter $presenter,
        string $locale,
        string $url,
        $category,
        array $ratingSummary,
    ): array {
        $images = $recipe->media
            ->filter(fn (Media $media): bool => $media->media_type === Media::TYPE_IMAGE)
            ->toBase()
            ->map(fn ($media): string => $media->publicUrl())
            ->whenEmpty(fn ($collection) => $collection->push($recipe->coverUrl()))
            ->unique()
            ->values()
            ->all();

        $recipeData = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Recipe',
            '@id' => $url.'#recipe',
            'url' => $url,
            'inLanguage' => $locale,
            'name' => $recipe->title,
            'description' => $recipe->summary ?: $recipe->seo_description,
            'image' => $images,
            'author' => ['@type' => 'Organization', 'name' => 'The Sunday Table'],
            'datePublished' => $recipe->published_at?->toIso8601String(),
            'dateModified' => $recipe->updated_at?->toIso8601String(),
            'prepTime' => 'PT'.$recipe->prep_minutes.'M',
            'cookTime' => 'PT'.$recipe->cook_minutes.'M',
            'totalTime' => 'PT'.($recipe->prep_minutes + $recipe->cook_minutes + $recipe->rest_minutes).'M',
            'recipeYield' => (string) $presenter->baseServings($recipe),
            'recipeCategory' => $recipe->categoryLabel(),
            'keywords' => $recipe->tags->map(fn ($tag) => $tag->translate($locale)?->name)->filter()->implode(', ') ?: null,
            'recipeIngredient' => $presenter->plainIngredients($recipe),
            'recipeInstructions' => collect($presenter->steps($recipe, $locale))
                ->map(fn (array $step): array => array_filter([
                    '@type' => 'HowToStep',
                    'position' => $step['number'],
                    'text' => $step['text'],
                    'image' => collect($step['images'])->pluck('url')->all(),
                    'video' => $step['video'] === null ? null : array_filter([
                        '@type' => 'VideoObject',
                        'name' => $step['video']['title'],
                        'description' => $step['video']['caption'],
                        'embedUrl' => $step['video']['embed_url'],
                    ], fn ($value) => $value !== null && $value !== ''),
                ], fn ($value) => $value !== null && $value !== []))
                ->all(),
            'aggregateRating' => $ratingSummary['count'] > 0 ? [
                '@type' => 'AggregateRating',
                'ratingValue' => round((float) $ratingSummary['average'], 2),
                'ratingCount' => $ratingSummary['count'],
                'bestRating' => 5,
                'worstRating' => 1,
            ] : null,
        ], fn ($value) => $value !== null && $value !== []);

        $breadcrumbs = [
            ['name' => __('site.home'), 'url' => route('home.localized', ['locale' => $locale])],
        ];

        if ($category) {
            $breadcrumbs[] = [
                'name' => (string) $category->translate($locale)?->name,
                'url' => route('categories.show', ['locale' => $locale, 'slug' => $category->translate($locale)?->slug]),
            ];
        }

        $breadcrumbs[] = ['name' => $recipe->title, 'url' => $url];

        return [
            $recipeData,
            [
                '@context' => 'https://schema.org',
                '@type' => 'BreadcrumbList',
                'itemListElement' => collect($breadcrumbs)->map(fn (array $crumb, int $index): array => [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'name' => $crumb['name'],
                    'item' => $crumb['url'],
                ])->all(),
            ],
        ];
    }

    /**
     * @return array{average: ?float, count: int}
     */
    private function ratingSummary(Recipe $recipe): array
    {
        $summary = $recipe->ratings()
            ->active()
            ->selectRaw('COUNT(*) AS rating_count, AVG(rating) AS average_rating')
            ->first();

        return [
            'average' => $summary->average_rating === null ? null : (float) $summary->average_rating,
            'count' => (int) $summary->rating_count,
        ];
    }
}
