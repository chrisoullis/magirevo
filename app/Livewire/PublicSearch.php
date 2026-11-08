<?php

namespace App\Livewire;

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\Tag;
use App\Services\RecipeSearch;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class PublicSearch extends Component
{
    #[Locked]
    public string $locale;

    public string $search = '';

    public function mount(string $locale): void
    {
        $this->locale = $locale;
        $this->search = is_string(request('q')) ? mb_substr(request('q'), 0, 100) : '';
    }

    public function updatedSearch(): void
    {
        $this->validateOnly('search', ['search' => ['string', 'max:100']]);
    }

    public function render(RecipeSearch $searcher): View
    {
        abort_unless(in_array($this->locale, config('translatable.locales'), true), 404);
        app()->setLocale($this->locale);
        $suggestions = [];
        if (mb_strlen(trim($this->search)) >= 2 && mb_strlen($this->search) <= 100) {
            foreach ($searcher->recipes(Recipe::query()->published(), $this->search)->with('translations')->orderByDesc('is_featured')->orderByDesc('published_at')->orderByDesc('id')->limit(4)->get() as $recipe) {
                $suggestions[] = ['kind' => 'recipe', 'title' => $recipe->translate($this->locale)->title, 'url' => route('recipes.show', ['locale' => $this->locale, 'slug' => $recipe->translate($this->locale)->slug], false)];
            }
            foreach (Ingredient::query()->publiclyVisible($this->locale)->searchCatalogue($this->search)->with('translations')->orderBy('code')->limit(3)->get() as $ingredient) {
                $suggestions[] = ['kind' => 'ingredient', 'title' => $ingredient->getTranslation($this->locale, false)->name, 'url' => $ingredient->encyclopediaUrl($this->locale)];
            }
            foreach (Category::query()->publiclyVisible()->whereHas('translations', fn ($texts) => $searcher->text($texts, ['name'], $this->search))
                ->whereHas('translations', fn ($texts) => $texts->where('locale', $this->locale)->whereNotNull('slug'))
                ->with('translations')->orderBy('sort_order')->limit(2)->get() as $category) {
                $suggestions[] = ['kind' => 'category', 'title' => $category->translate($this->locale)->name, 'url' => route('categories.show', ['locale' => $this->locale, 'slug' => $category->getTranslation($this->locale, false)->slug], false)];
            }
            foreach (Tag::query()->where('is_active', true)->editorial()->whereHas('recipes', fn ($recipes) => $recipes->published())
                ->whereHas('translations', fn ($texts) => $searcher->text($texts, ['name'], $this->search))->with('translations')->orderBy('sort_order')->limit(2)->get() as $tag) {
                $suggestions[] = ['kind' => 'tag', 'title' => $tag->translate($this->locale)?->name ?? $tag->code, 'url' => route('home.localized', ['locale' => $this->locale, 'tag' => $tag->code], false).'#recipes'];
            }
        }

        return view('livewire.public-search', compact('suggestions'));
    }
}
