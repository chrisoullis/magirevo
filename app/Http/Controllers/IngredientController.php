<?php

namespace App\Http\Controllers;

use App\Models\Difficulty;
use App\Models\Ingredient;
use App\Models\IngredientSubstitution;
use App\Models\Recipe;
use App\Support\IngredientAlphabet;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class IngredientController extends Controller
{
    public function index(string $locale): Response
    {
        request()->validate(['search' => ['nullable', 'string', 'max:100'], 'group' => ['nullable', 'string', 'max:100'], 'letter' => ['nullable', Rule::in(IngredientAlphabet::letters($locale))], 'sort' => ['nullable', Rule::in(['az', 'za'])], 'page' => ['sometimes', 'integer', 'between:1,10000']]);

        return response()->view('ingredients.index', [
            'locale' => $locale,
            'alternateUrls' => collect(config('translatable.locales'))->mapWithKeys(fn (string $language): array => [$language => route('ingredients.index', ['locale' => $language])])->all(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function show(string $locale, string $slug): Response
    {
        $ingredient = Ingredient::query()->publiclyVisible($locale)->whereTranslation('slug', $slug, $locale)
            ->with(['translations', 'aliases', 'catalogGroup.translations', 'publicPrimaryImage.media.translations', 'publicPrimaryImage.thumbnail'])->firstOrFail();
        $alternates = [];
        $switchUrls = [];
        foreach (config('translatable.locales') as $language) {
            if ($ingredient->hasPublicTranslation($language)) {
                $alternates[$language] = route('ingredients.show', ['locale' => $language, 'slug' => $ingredient->getTranslation($language, false)->slug]);
            }
            $switchUrls[$language] = $alternates[$language] ?? route('ingredients.index', ['locale' => $language]);
        }
        request()->validate(['recipes_page' => ['sometimes', 'integer', 'between:1,10000'], 'substitutions_page' => ['sometimes', 'integer', 'between:1,10000'], 'photos_page' => ['sometimes', 'integer', 'between:1,10000']]);
        $recipes = Recipe::query()->published()->whereHas('recipeIngredients', fn ($lines) => $lines->where('ingredient_id', $ingredient->id))
            ->with(['translations', 'categories.translations', 'media.translations', 'currentRevision:id,status,media_snapshot'])
            ->withCount(['ratings as public_rating_count' => fn ($ratings) => $ratings->active()])
            ->withAvg(['ratings as public_rating_average' => fn ($ratings) => $ratings->active()], 'rating')->orderByDesc('published_at')->orderByDesc('id')
            ->paginate(9, ['recipes.*'], 'recipes_page')->withQueryString();
        $difficultyLabels = Difficulty::query()->whereIn('code', $recipes->getCollection()->pluck('difficulty')->filter()->unique())
            ->with('translations')->get()->mapWithKeys(fn (Difficulty $difficulty): array => [$difficulty->code => $difficulty->getTranslation($locale)?->name ?? $difficulty->code])->all();
        $substitutions = IngredientSubstitution::query()->approved()->where('ingredient_id', $ingredient->id)->whereNull('recipe_id')
            ->whereHas('replacementIngredient', fn ($replacement) => $replacement->publiclyVisible($locale))
            ->with(['translations', 'replacementIngredient.translations', 'replacementIngredient.catalogGroup.translations'])->orderBy('id')->paginate(12, ['*'], 'substitutions_page')->withQueryString();
        $gallery = $ingredient->imageAssignments()->publiclyUsable()->where('role', 'gallery')->with(['media.translations', 'thumbnail'])
            ->orderBy('sort_order')->orderBy('id')->paginate(9, ['*'], 'photos_page')->withQueryString();

        return response()->view('ingredients.show', [
            'locale' => $locale, 'ingredient' => $ingredient, 'translation' => $ingredient->getTranslation($locale, false),
            'alternateUrls' => $alternates, 'languageSwitchUrls' => $switchUrls,
            'recipes' => $recipes, 'difficultyLabels' => $difficultyLabels, 'substitutions' => $substitutions, 'gallery' => $gallery,
        ])->header('Cache-Control', 'private, no-store');
    }
}
