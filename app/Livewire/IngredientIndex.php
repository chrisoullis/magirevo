<?php

namespace App\Livewire;

use App\Models\Ingredient;
use App\Models\IngredientCatalogGroup;
use App\Models\IngredientTranslation;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class IngredientIndex extends Component
{
    use WithPagination;

    #[Locked]
    public string $locale = 'en';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $group = '';

    public function mount(string $locale): void
    {
        $this->locale = $locale;
        $this->guardLocale();
    }

    public function updatedSearch(): void
    {
        $this->validateOnly('search', ['search' => ['nullable', 'string', 'max:100']]);
        $this->resetPage();
    }

    public function updatedGroup(): void
    {
        $this->validateOnly('group', ['group' => ['nullable', 'string', 'max:100']]);
        $this->resetPage();
    }

    public function searchIngredients(): void
    {
        $this->validate(['search' => ['nullable', 'string', 'max:100'], 'group' => ['nullable', 'string', 'max:100']]);
        $this->resetPage();
    }

    public function render(): View
    {
        $this->guardLocale();
        $ingredients = Ingredient::query()->publiclyVisible($this->locale)->searchCatalogue($this->search)
            ->when($this->group !== '', fn ($query) => $query->whereHas('catalogGroup', fn ($group) => $group->where('code', mb_substr($this->group, 0, 100))))
            ->with(['translations', 'catalogGroup.translations', 'publicPrimaryImage.media.translations', 'publicPrimaryImage.thumbnail'])
            ->orderBy(IngredientTranslation::query()->select('name')->whereColumn('ingredient_id', 'ingredients.id')->where('locale', $this->locale)->limit(1))
            ->orderBy('ingredients.code')->paginate(24)
            ->withPath(route('ingredients.index', ['locale' => $this->locale], false))
            ->appends(['search' => $this->search, 'group' => $this->group]);
        $groups = IngredientCatalogGroup::query()->where('is_active', true)->with('translations')
            ->whereHas('ingredients', fn ($query) => $query->publiclyVisible($this->locale))->orderBy('sort_order')->orderBy('code')->get();

        return view('livewire.ingredient-index', ['ingredients' => $ingredients, 'groups' => $groups]);
    }

    private function guardLocale(): void
    {
        abort_unless(in_array($this->locale, config('translatable.locales'), true), 404);
        app()->setLocale($this->locale);
    }
}
