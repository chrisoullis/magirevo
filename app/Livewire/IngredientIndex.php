<?php

namespace App\Livewire;

use App\Models\Ingredient;
use App\Models\IngredientCatalogGroup;
use App\Models\IngredientTranslation;
use App\Services\RecipeSearch;
use App\Support\IngredientAlphabet;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
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

    #[Url(except: '')]
    public string $letter = '';

    #[Url(except: 'az')]
    public string $sort = 'az';

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'group' => ['nullable', 'string', 'max:100'],
            'letter' => ['nullable', Rule::in(IngredientAlphabet::letters($this->locale))],
            'sort' => ['required', Rule::in(['az', 'za'])],
        ];
    }

    public function messages(): array
    {
        return collect(array_keys($this->rules()))->flatMap(fn ($field) => collect(['string', 'max', 'in', 'required'])->mapWithKeys(fn ($rule) => [$field.'.'.$rule => __('discovery.invalid_filters')]))->all();
    }

    public function updated(string $property): void
    {
        if (array_key_exists($property, $this->rules())) {
            $this->resetPage();
            $this->validateOnly($property);
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'group', 'letter', 'sort');
        $this->resetPage();
    }

    public function mount(string $locale): void
    {
        $this->locale = $locale;
        $this->guardLocale();
    }

    public function searchIngredients(): void
    {
        $this->validate();
        $this->resetPage();
    }

    public function render(): View
    {
        $this->guardLocale();
        $valid = Validator::make(['search' => $this->search, 'group' => $this->group, 'letter' => $this->letter, 'sort' => $this->sort], $this->rules())->passes();
        $ingredients = Ingredient::query()->when(! $valid, fn ($query) => $query->whereRaw('1 = 0'))->publiclyVisible($this->locale)->searchCatalogue($this->search)
            ->when($this->group !== '', fn ($query) => $query->whereHas('catalogGroup', fn ($group) => $group->where('code', mb_substr($this->group, 0, 100))))
            ->when($this->letter !== '' && in_array($this->letter, IngredientAlphabet::letters($this->locale), true), fn ($query) => $query->whereHas('translations', fn ($texts) => app(RecipeSearch::class)->text($texts, ['name'], $this->letter, prefix: true, locale: $this->locale)))
            ->with(['translations', 'catalogGroup.translations', 'publicPrimaryImage.media.translations', 'publicPrimaryImage.thumbnail'])
            ->orderBy(IngredientTranslation::query()->select('name')->whereColumn('ingredient_id', 'ingredients.id')->where('locale', $this->locale)->limit(1), $this->sort === 'za' ? 'desc' : 'asc')
            ->orderBy('ingredients.code')->paginate(24)
            ->withPath(route('ingredients.index', ['locale' => $this->locale], false))
            ->appends(['search' => $this->search, 'group' => $this->group, 'letter' => $this->letter, 'sort' => $this->sort]);
        $groups = IngredientCatalogGroup::query()->where('is_active', true)->with('translations')
            ->whereHas('ingredients', fn ($query) => $query->publiclyVisible($this->locale))->orderBy('sort_order')->orderBy('code')->get();

        return view('livewire.ingredient-index', ['ingredients' => $ingredients, 'groups' => $groups, 'alphabet' => IngredientAlphabet::letters($this->locale)]);
    }

    private function guardLocale(): void
    {
        abort_unless(in_array($this->locale, config('translatable.locales'), true), 404);
        app()->setLocale($this->locale);
    }
}
