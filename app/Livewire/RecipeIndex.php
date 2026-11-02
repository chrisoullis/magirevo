<?php

namespace App\Livewire;

use App\Enums\TagClassification;
use App\Models\Category;
use App\Models\Recipe;
use App\Models\Tag;
use App\Services\DietaryClaimVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

class RecipeIndex extends Component
{
    private const PAGE_SIZE = 9;

    private const SORTS = ['featured', 'newest', 'quickest'];

    private const TIME_LIMITS = [30, 60, 90];

    public string $locale = 'en';

    /** Set on category pages; the category filter is then fixed. */
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

    public int $limit = self::PAGE_SIZE;

    public function mount(string $locale = 'en', ?string $categoryCode = null, bool $showHero = true): void
    {
        $this->locale = $locale;
        $this->categoryCode = $categoryCode;
        $this->showHero = $showHero;

        $this->guardLocale();
    }

    public function updated(string $property): void
    {
        if ($property !== 'limit') {
            $this->limit = self::PAGE_SIZE;
        }
    }

    public function loadMore(): void
    {
        $this->limit += self::PAGE_SIZE;
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'category', 'tag', 'difficulty', 'time', 'sort', 'limit');
    }

    public function render(): View
    {
        $this->guardLocale();

        $query = $this->filteredQuery();
        $total = (clone $query)->count();

        $recipes = $this->applySort($query)
            ->with(['translations', 'categories.translations', 'media.translations'])
            ->limit($this->limit)
            ->get();

        $featured = $this->showHero
            ? Recipe::query()->published()->with(['translations', 'media.translations'])
                ->orderByDesc('is_featured')->orderByDesc('published_at')->first()
            : null;

        return view('livewire.recipe-index', [
            'recipes' => $recipes,
            'total' => $total,
            'hasMore' => $total > $recipes->count(),
            'featured' => $featured,
            'categories' => $this->categoryOptions(),
            'tags' => $this->tagOptions(),
            'difficulties' => $this->difficultyOptions(),
            'timeLimits' => self::TIME_LIMITS,
            'hasFilters' => $this->hasFilters(),
        ]);
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
            || $this->sort !== 'featured';
    }

    private function filteredQuery(): Builder
    {
        $categoryCode = $this->categoryCode ?? ($this->category !== '' ? $this->category : null);

        return Recipe::query()
            ->published()
            ->when($categoryCode !== null, fn (Builder $query) => $query->inCategory($categoryCode))
            ->when($this->tag !== '', fn (Builder $query) => $this->applyTagFilter($query))
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

        if ($tag->classification->isFreelyPublic()) {
            return $query->whereHas('tags', fn (Builder $match) => $match->where('tags.code', $this->tag));
        }

        if ($tag->classification->isDietaryClaim()) {
            return $query->whereIn('recipes.id', app(DietaryClaimVisibility::class)->recipeIdsWithPublicClaim($this->tag));
        }

        return $query->whereRaw('1 = 0');
    }

    private function applySearch(Builder $query, string $term): Builder
    {
        $like = '%'.addcslashes(mb_strtolower($term), '%_\\').'%';
        $locale = $this->locale;
        $fold = $this->hasAccentFolding() ? fn (string $column): string => "f_unaccent(lower({$column}))" : fn (string $column): string => "lower({$column})";
        $pattern = $this->hasAccentFolding() ? 'f_unaccent(?)' : '?';

        return $query->where(function (Builder $query) use ($like, $locale, $fold, $pattern): void {
            $query->whereHas('translations', fn (Builder $translation) => $translation
                ->where('locale', $locale)
                ->where(fn (Builder $match) => $match
                    ->whereRaw($fold('title')." LIKE {$pattern}", [$like])
                    ->orWhereRaw($fold('summary')." LIKE {$pattern}", [$like])))
                ->orWhereHas('tags', fn (Builder $tag) => $tag
                    ->whereIn('classification', TagClassification::freelyPublicValues())
                    ->whereHas('translations', fn (Builder $translation) => $translation
                        ->where('locale', $locale)->whereRaw($fold('name')." LIKE {$pattern}", [$like])))
                ->orWhereHas('recipeIngredients.ingredient.translations', fn (Builder $translation) => $translation
                    ->where('locale', $locale)->whereRaw($fold('name')." LIKE {$pattern}", [$like]));
        });
    }

    private function hasAccentFolding(): bool
    {
        if (DB::getDriverName() !== 'pgsql') {
            return false;
        }

        return (bool) DB::scalar("select to_regproc('f_unaccent') is not null");
    }

    private function applySort(Builder $query): Builder
    {
        return match (in_array($this->sort, self::SORTS, true) ? $this->sort : 'featured') {
            'newest' => $query->orderByDesc('published_at')->orderByDesc('id'),
            'quickest' => $query->orderByRaw('(prep_minutes + cook_minutes)')->orderByDesc('published_at'),
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
        $claimCodes = array_keys(app(DietaryClaimVisibility::class)->publicClaimIndex());

        return Tag::query()
            ->where('is_active', true)
            ->where(fn (Builder $tags) => $tags
                ->where(fn (Builder $editorial) => $editorial
                    ->whereIn('classification', TagClassification::freelyPublicValues())
                    ->whereHas('recipes', fn (Builder $recipe) => $recipe->published()))
                ->orWhereIn('code', $claimCodes))
            ->with('translations')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Tag $tag): array => ['code' => $tag->code, 'name' => (string) $tag->translate($this->locale)?->name])
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
