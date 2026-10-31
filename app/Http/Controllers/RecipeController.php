<?php

namespace App\Http\Controllers;

use App\Models\Recipe;
use App\Services\RecipeContentPresenter;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class RecipeController extends Controller
{
    public function show(string $locale, string $slug, RecipeContentPresenter $presenter): View
    {
        $recipe = Recipe::query()
            ->published()
            ->with(['translations', 'categories.translations', 'tags.translations', 'media.translations', 'author'])
            ->whereTranslation('slug', $slug, $locale)
            ->firstOrFail();

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

        $alternateUrls = collect(config('translatable.locales'))
            ->mapWithKeys(fn (string $alternate): array => [
                $alternate => route('recipes.show', ['locale' => $alternate, 'slug' => $recipe->translate($alternate)->slug]),
            ])
            ->all();

        return view('recipes.show', [
            'locale' => $locale,
            'recipe' => $recipe,
            'category' => $category,
            'related' => $related,
            'steps' => $presenter->steps($recipe),
            'baseServings' => $presenter->baseServings($recipe),
            'gallery' => $recipe->media->filter(fn ($media) => $media->pivot->role === 'gallery')->values(),
            'alternateUrls' => $alternateUrls,
            'structuredData' => $this->structuredData($recipe, $presenter, $locale, $alternateUrls[$locale], $category),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function structuredData(Recipe $recipe, RecipeContentPresenter $presenter, string $locale, string $url, $category): array
    {
        $images = $recipe->media
            ->toBase()
            ->map(fn ($media): string => Storage::disk($media->disk)->url($media->file_path))
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
            'recipeInstructions' => collect($presenter->steps($recipe))
                ->map(fn (array $step, int $index): array => ['@type' => 'HowToStep', 'position' => $index + 1, 'text' => $step['text']])
                ->all(),
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
}
