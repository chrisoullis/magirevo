<?php

namespace App\Livewire;

use App\Http\Requests\PublicRecipeFiltersRequest;
use App\Models\Category;
use App\Models\Difficulty;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeRating;
use App\Models\Tag;
use App\Services\DietaryClaimVisibility;
use App\Services\RecipeSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class RecipeIndex extends Component
{
    use WithPagination;

    private const PAGE_SIZE = 9;

    private const SORTS = ['featured', 'newest', 'quickest', 'rating'];

    private const TIME_LIMITS = [30, 60, 90];

    public string $locale = 'en';

    /** Set on category pages; the category filter is then fixed. */
    #[Locked]
    public ?string $categoryCode = null;

    public bool $showHero = true;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'category', except: '')]
    public string $category = '';

    #[Url(except: '')]
    public string $tag = '';

    #[Url(except: '')]
    public string $difficulty = '';

    #[Url(except: '')]
    public string $time = '';

    #[Url(except: 'featured')]
    public string $sort = 'featured';

    #[Url(except: '')]
    public string $collection = '';

    #[Url(except: '')]
    public string $cuisine = '';

    #[Url(except: '')]
    public string $method = '';

    #[Url(except: '')]
    public string $dietary = '';

    #[Url(except: '')]
    public string $rating = '';

    #[Url(except: [])]
    public array $ingredients = [];

    public string $ingredientSearch = '';

    private array $publicClaims = [];

    public function messages(): array
    {
        return ['search.max' => __('discovery.invalid_filters'), 'ingredientSearch.max' => __('discovery.invalid_filters'), ...(new PublicRecipeFiltersRequest)->messages()];
    }

    public function rules(): array
    {
        $rules = (new PublicRecipeFiltersRequest)->rules();
        $rules['search'] = $rules['q'];
        $rules['ingredientSearch'] = ['string', 'max:100'];
        unset($rules['q'], $rules['recipes_page']);

        return $rules;
    }

    public function selectIngredient(string $code): void
    {
        abort_unless(Ingredient::query()->publiclyVisible($this->locale)->where('code', $code)
            ->whereHas('recipeIngredients.recipe', fn ($recipes) => $recipes->published())->exists(), 404);
        if (count($this->ingredients) >= 5 && ! in_array($code, $this->ingredients, true)) {
            throw ValidationException::withMessages(['ingredients' => __('discovery.ingredient_limit')]);
        }
        if (! in_array($code, $this->ingredients, true)) {
            $this->ingredients[] = $code;
        }
        $this->ingredientSearch = '';
        $this->resetPage('recipes_page');
    }

    public function removeIngredient(string $code): void
    {
        $this->ingredients = array_values(array_filter($this->ingredients, fn ($value) => $value !== $code));
        $this->resetPage('recipes_page');
    }

    public function mount(string $locale = 'en', ?string $categoryCode = null, bool $showHero = true): void
    {
        $this->locale = $locale;
        $this->categoryCode = $categoryCode;
        $this->showHero = $showHero;

        $this->guardLocale();
    }

    public function updated(string $property): void
    {
        if ($property === 'ingredients' || str_starts_with($property, 'ingredients.')) {
            $this->resetPage('recipes_page');
            $this->validate(array_intersect_key($this->rules(), array_flip(['ingredients', 'ingredients.*'])));

            return;
        }
        if (in_array($property, ['search', 'category', 'tag', 'difficulty', 'time', 'sort', 'collection', 'cuisine', 'method', 'dietary', 'rating', 'ingredients', 'ingredientSearch'], true)) {
            $this->resetPage('recipes_page');
            $this->validateOnly($property);
        }
    }

    public function loadMore(): void
    {
        $this->nextPage('recipes_page');
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'category', 'tag', 'difficulty', 'time', 'sort', 'collection', 'cuisine', 'method', 'dietary', 'rating', 'ingredients', 'ingredientSearch');
        $this->resetPage('recipes_page');
    }

    public function render(): View
    {
        $this->guardLocale();

        $this->publicClaims = app(DietaryClaimVisibility::class)->publicClaimIndex();
        $valid = Validator::make($this->filterState(), $this->rules())->passes();
        $query = $valid ? $this->filteredQuery() : Recipe::query()->published()->whereRaw('1 = 0');

        $recipes = $this->applySort($query->withCount(['ratings as public_rating_count' => fn ($ratings) => $ratings->active()])
            ->withAvg(['ratings as public_rating_average' => fn ($ratings) => $ratings->active()], 'rating'))
            ->with(['translations', 'categories.translations', 'media.translations', 'currentRevision:id,status,media_snapshot'])
            ->paginate(self::PAGE_SIZE, ['recipes.*'], 'recipes_page');

        $tags = $this->tagOptions();
        $recipeClaims = [];
        foreach ($tags->where('is_editorial', false) as $claim) {
            foreach ($this->publicClaims[$claim['code']] ?? [] as $recipeId) {
                if (in_array($recipeId, $recipes->getCollection()->modelKeys(), true)) {
                    $recipeClaims[$recipeId][] = $claim['name'];
                }
            }
        }
        $safeCodes = array_slice(array_filter($this->ingredients, fn ($code) => is_string($code) && mb_strlen($code) <= 100), 0, 5);
        $selectedIngredients = Ingredient::query()->publiclyVisible($this->locale)->whereIn('code', $safeCodes)->with(['translations', 'catalogGroup.translations'])->get();
        $ingredientResults = count($safeCodes) < 5 && mb_strlen(trim($this->ingredientSearch)) >= 2 && mb_strlen($this->ingredientSearch) <= 100
            ? Ingredient::query()->publiclyVisible($this->locale)->searchCatalogue($this->ingredientSearch)->whereNotIn('code', $safeCodes)
                ->whereHas('recipeIngredients.recipe', fn ($recipes) => $recipes->published())->with('translations')->orderBy('code')->limit(8)->get()
            : collect();
        $savedIds = auth()->user()?->hasVerifiedEmail()
            ? auth()->user()->favoriteRecipes()->whereIn('recipes.id', $recipes->getCollection()->modelKeys())->pluck('recipes.id') : collect();

        return view('livewire.recipe-index', [
            'recipes' => $recipes,
            'total' => $recipes->total(),
            'hasMore' => $recipes->hasMorePages(),
            'categories' => $this->categoryOptions(),
            'tags' => $tags,
            'recipeClaims' => $recipeClaims,
            'selectedIngredients' => $selectedIngredients,
            'ingredientResults' => $ingredientResults,
            'savedIds' => $savedIds,
            'difficulties' => $this->difficultyOptions(),
            'timeLimits' => self::TIME_LIMITS,
            'hasFilters' => $this->hasFilters(),
            'difficultyLabels' => Difficulty::query()->with('translations')->get()->mapWithKeys(fn ($difficulty) => [$difficulty->code => $difficulty->translate($this->locale)?->name ?? $difficulty->code])->all(),
        ]);
    }

    private function filterState(): array
    {
        return array_intersect_key(get_object_vars($this), $this->rules());
    }

    private function guardLocale(): void
    {
        abort_unless(in_array($this->locale, config('translatable.locales'), true), 404);

        app()->setLocale($this->locale);
    }

    private function hasFilters(): bool
    {
        return trim($this->search) !== ''
            || $this->category !== ''
            || $this->tag !== ''
            || $this->difficulty !== ''
            || $this->time !== ''
            || $this->sort !== 'featured'
            || $this->collection !== '' || $this->cuisine !== '' || $this->method !== ''
            || $this->dietary !== '' || $this->rating !== '' || $this->ingredients !== [];
    }

    private function filteredQuery(): Builder
    {
        $categoryCode = $this->categoryCode ?? ($this->category !== '' ? $this->category : null);

        return Recipe::query()
            ->published()
            ->when($this->collection !== '', fn (Builder $query) => app(RecipeSearch::class)->collection($query, $this->collection))
            ->when($categoryCode !== null, fn (Builder $query) => $query->inCategory($categoryCode))
            ->when($this->tag !== '', fn (Builder $query) => $this->applyTagFilter($query))
            ->when($this->cuisine !== '', fn (Builder $query) => $query->whereHas('tags', fn ($tags) => $tags->where('is_active', true)->editorial()->where('group_code', 'cuisine')->where('code', $this->cuisine)))
            ->when($this->method !== '', fn (Builder $query) => $query->whereHas('tags', fn ($tags) => $tags->where('is_active', true)->editorial()->where('group_code', 'cooking_method')->where('code', $this->method)))
            ->when($this->dietary !== '', fn (Builder $query) => $query->whereIn('recipes.id', $this->publicClaims[$this->dietary] ?? []))
            ->when($this->rating !== '', fn (Builder $query) => $query->whereIn('recipes.id', RecipeRating::query()->active()->select('recipe_id')->groupBy('recipe_id')->havingRaw('AVG(rating) >= ?', [(int) $this->rating])))
            ->when($this->ingredients !== [], function (Builder $query): void {
                foreach ($this->ingredients as $code) {
                    $query->whereHas('recipeIngredients.ingredient', fn ($ingredients) => $ingredients->publiclyVisible($this->locale)->where('code', $code));
                }
            })
            ->when($this->difficulty !== '', fn (Builder $query) => $query->where('difficulty', $this->difficulty))
            ->when(in_array((int) $this->time, self::TIME_LIMITS, true), fn (Builder $query) => $query->whereRaw('(prep_minutes + cook_minutes) <= ?', [(int) $this->time]))
            ->when(trim($this->search) !== '', fn (Builder $query) => $this->applySearch($query, trim($this->search)));
    }

    /**
     * Editorial tags filter by association. Dietary claim tags match only recipes with a current approved claim.
     */
    private function applyTagFilter(Builder $query): Builder
    {
        $tag = Tag::query()->where('code', $this->tag)->where('is_active', true)->first();

        if ($tag === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($tag->isEditorial()) {
            return $query->whereHas('tags', fn (Builder $match) => $match->where('tags.code', $this->tag));
        }

        if ($tag->classification->isDietaryClaim()) {
            return $query->whereIn('recipes.id', $this->publicClaims[$this->tag] ?? []);
        }

        return $query->whereRaw('1 = 0');
    }

    private function applySearch(Builder $query, string $term): Builder
    {
        return app(RecipeSearch::class)->recipes($query, $term);
    }

    private function applySort(Builder $query): Builder
    {
        return match (in_array($this->sort, self::SORTS, true) ? $this->sort : 'featured') {
            'newest' => $query->orderByDesc('published_at')->orderByDesc('id'),
            'quickest' => $query->orderByRaw('(prep_minutes + cook_minutes)')->orderByDesc('published_at')->orderByDesc('id'),
            'rating' => $query->orderByRaw('public_rating_average DESC NULLS LAST')->orderByDesc('public_rating_count')->orderByDesc('id'),
            default => $query->orderByDesc('is_featured')->orderByDesc('published_at')->orderByDesc('id'),
        };
    }

    /**
     * Publicly visible root categories that have published recipes, each with its non-empty subcategories.
     * Root counts are distinct recipes across the root and its subcategories.
     *
     * @return Collection<int, array{code: string, name: string, slug: string, count: int, children: list<array{code: string, name: string, slug: string, count: int}>}>
     */
    private function categoryOptions(): Collection
    {
        $categories = Category::query()
            ->publiclyVisible()
            ->withRecipeCounts(publishedOnly: true)
            ->with('translations')
            ->ordered()
            ->get();

        $childrenByParent = $categories->whereNotNull('parent_id')->groupBy('parent_id');

        return $categories
            ->whereNull('parent_id')
            ->filter(fn (Category $category): bool => (int) $category->published_total_recipes_count > 0)
            ->map(fn (Category $category): ?array => $this->categoryOption($category, (int) $category->published_total_recipes_count, $childrenByParent->get($category->getKey(), collect())))
            ->filter()
            ->values();
    }

    /**
     * @param  Collection<int, Category>  $children
     * @return array<string, mixed>|null
     */
    private function categoryOption(Category $category, int $count, Collection $children): ?array
    {
        $translation = $category->translate($this->locale);

        if (blank($translation?->slug)) {
            return null;
        }

        return [
            'code' => $category->code,
            'name' => (string) $translation->name,
            'slug' => (string) $translation->slug,
            'count' => $count,
            'children' => $children
                ->filter(fn (Category $child): bool => (int) $child->published_direct_recipes_count > 0)
                ->map(fn (Category $child): ?array => $this->categoryOption($child, (int) $child->published_direct_recipes_count, collect()))
                ->filter()
                ->values()
                ->all(),
        ];
    }

    /**
     * @return Collection<int, array{code: string, name: string}>
     */
    private function tagOptions(): Collection
    {
        $claimCodes = array_keys($this->publicClaims);

        return Tag::query()
            ->where('is_active', true)
            ->where(fn (Builder $tags) => $tags
                ->where(fn (Builder $editorial) => $editorial
                    ->editorial()
                    ->whereHas('recipes', fn (Builder $recipe) => $recipe->published()))
                ->orWhereIn('code', $claimCodes))
            ->with('translations')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Tag $tag): array => ['code' => $tag->code, 'name' => (string) $tag->translate($this->locale)?->name, 'group' => $tag->groupLabel($this->locale), 'group_code' => $tag->group_code, 'is_editorial' => $tag->isEditorial()])
            ->filter(fn (array $tag): bool => $tag['name'] !== '')
            ->values();
    }

    /**
     * @return Collection<int, string>
     */
    private function difficultyOptions(): Collection
    {
        return Recipe::query()->published()->whereNotNull('difficulty')->distinct()->orderBy('difficulty')->pluck('difficulty');
    }
}
