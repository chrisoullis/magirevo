<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Contracts\View\View;

class CategoryController extends Controller
{
    public function show(string $locale, string $slug): View
    {
        $category = Category::query()
            ->publiclyVisible()
            ->with(['translations', 'parent.translations', 'media.translations'])
            ->whereTranslation('slug', $slug, $locale)
            ->firstOrFail();

        $alternates = collect(config('translatable.locales'))
            ->mapWithKeys(fn (string $alternate): array => [
                $alternate => route('categories.show', ['locale' => $alternate, 'slug' => $category->translate($alternate)?->slug ?? $slug]),
            ])
            ->all();

        $subcategories = $category->isRoot()
            ? Category::query()
                ->publiclyVisible()
                ->where('parent_id', $category->getKey())
                ->withRecipeCounts(publishedOnly: true)
                ->with(['translations', 'media.translations'])
                ->ordered()
                ->get()
                ->filter(fn (Category $child): bool => (int) $child->published_direct_recipes_count > 0 && filled($child->translate($locale)?->slug))
                ->values()
            : collect();

        return view('categories.show', [
            'subcategories' => $subcategories,
            'locale' => $locale,
            'category' => $category,
            'translation' => $category->translate($locale),
            'alternateUrls' => $alternates,
        ]);
    }
}
