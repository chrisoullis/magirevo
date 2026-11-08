<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Difficulty;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class HomepageData
{
    public function recipes(): Builder
    {
        return Recipe::query()->published()->with(['translations', 'categories.translations', 'media.translations', 'currentRevision:id,status,media_snapshot'])
            ->withCount(['ratings as public_rating_count' => fn ($ratings) => $ratings->active()])
            ->withAvg(['ratings as public_rating_average' => fn ($ratings) => $ratings->active()], 'rating');
    }

    public function sections(string $locale, ?User $user = null): array
    {
        $featured = $this->recipes()->orderByDesc('is_featured')->orderByDesc('published_at')->orderByDesc('id')->limit(6)->get();
        $hero = $featured->first();
        $recent = $this->recipes()->whereNotIn('id', $featured->modelKeys())->latest('published_at')->orderByDesc('id')->limit(3)->get();
        $quick = $this->recipes()->whereRaw('(prep_minutes + cook_minutes) <= ?', [30])->orderByRaw('(prep_minutes + cook_minutes)')->orderByDesc('id')->limit(3)->get();
        $ingredients = Ingredient::query()->publiclyVisible($locale)
            ->with(['translations', 'catalogGroup.translations', 'publicPrimaryImage.media.translations', 'publicPrimaryImage.thumbnail'])
            ->orderByDesc('id')->limit(6)->get();
        $difficultyLabels = Difficulty::query()->with('translations')->get()->mapWithKeys(fn (Difficulty $difficulty): array => [$difficulty->code => $difficulty->translate($locale)?->name ?? $difficulty->code])->all();
        $claims = app(DietaryClaimVisibility::class)->publicClaimIndex();
        $categories = $this->quickCategories($locale, $hero, $quick->first(), $claims);

        $ids = $featured->merge($recent)->merge($quick)->modelKeys();
        $savedIds = $user?->hasVerifiedEmail() ? $user->favoriteRecipes()->whereIn('recipes.id', $ids)->pluck('recipes.id') : collect();

        return compact('savedIds', 'featured', 'hero', 'recent', 'quick', 'ingredients', 'difficultyLabels', 'categories');
    }

    private function quickCategories(string $locale, ?Recipe $hero, ?Recipe $quick, array $claims): array
    {
        $catalogue = fn (array $filters = []): string => route('home.localized', ['locale' => $locale, ...$filters], false).'#recipes';
        $items = [
            ['label' => __('homepage.all_recipes'), 'url' => $catalogue(), 'image' => $hero?->coverUrl(), 'icon' => 'book'],
            ['label' => __('homepage.under_30'), 'url' => $catalogue(['time' => 30]), 'image' => $quick?->coverUrl(), 'icon' => 'clock'],
        ];
        foreach (Category::query()->publiclyVisible()->whereIn('code', ['main_dishes', 'desserts_sweets'])->with(['translations', 'media.translations'])->ordered()->get() as $category) {
            if (filled($category->getTranslation($locale, false)?->slug)) {
                $items[] = ['label' => $category->getTranslation($locale, false)->name, 'url' => route('categories.show', ['locale' => $locale, 'slug' => $category->getTranslation($locale, false)->slug], false), 'image' => $category->media_id ? $category->imageUrl() : null, 'icon' => 'book'];
            }
        }
        foreach (['local' => 'local_recipes', 'world' => 'world_cuisines', 'seasonal' => 'seasonal'] as $collection => $label) {
            if (app(RecipeSearch::class)->collection(Recipe::query()->published(), $collection)->exists()) {
                $items[] = ['label' => __('homepage.'.$label), 'url' => $catalogue(['collection' => $collection]), 'image' => null, 'icon' => 'leaf'];
            }
        }
        if ($claims !== []) {
            foreach (Tag::query()->whereIn('code', array_keys($claims))->with('translations')->orderBy('sort_order')->limit(2)->get() as $tag) {
                $items[] = ['label' => $tag->translate($locale)?->name ?? $tag->code, 'url' => $catalogue(['tag' => $tag->code]), 'image' => null, 'icon' => 'check'];
            }
        }

        return $items;
    }
}
