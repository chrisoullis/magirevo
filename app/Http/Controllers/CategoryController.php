<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Contracts\View\View;

class CategoryController extends Controller
{
    public function show(string $locale, string $slug): View
    {
        $category = Category::query()
            ->where('is_active', true)
            ->with('translations')
            ->whereTranslation('slug', $slug, $locale)
            ->firstOrFail();

        $alternates = collect(config('translatable.locales'))
            ->mapWithKeys(fn (string $alternate): array => [
                $alternate => route('categories.show', ['locale' => $alternate, 'slug' => $category->translate($alternate)?->slug ?? $slug]),
            ])
            ->all();

        return view('categories.show', [
            'locale' => $locale,
            'category' => $category,
            'translation' => $category->translate($locale),
            'alternateUrls' => $alternates,
        ]);
    }
}
