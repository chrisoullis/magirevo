<?php

namespace App\Models;

use App\Services\CategoryHierarchyService;
use Astrotomic\Translatable\Translatable;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    use Translatable;

    protected $fillable = [
        'code',
        'parent_id',
        'media_id',
        'is_active',
        'sort_order',
    ];

    public array $translatedAttributes = [
        'name',
        'slug',
        'description',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $category): void {
            if (! array_key_exists('sort_order', $category->getAttributes())) {
                $category->sort_order = app(CategoryHierarchyService::class)->nextSortOrder($category->parent_id);
            }
        });

        static::saving(function (self $category): void {
            app(CategoryHierarchyService::class)->assertValid($category);
        });
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    public function imageUrl(): string
    {
        return $this->media?->publicUrl() ?? asset('images/category-placeholder.svg');
    }

    public function imageAlt(?string $locale = null): string
    {
        return $this->media?->translate($locale)?->alt_text ?: '';
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->ordered();
    }

    public function recipes(): BelongsToMany
    {
        return $this->belongsToMany(Recipe::class, 'recipe_category')->withTimestamps();
    }

    public function isInUse(): bool
    {
        return $this->children()->exists() || $this->recipes()->withTrashed()->exists();
    }

    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    /**
     * A category is publicly visible when it is active and, for subcategories, its parent is active too.
     * Children are never deactivated automatically; visibility is derived.
     */
    public function isEffectivelyActive(): bool
    {
        return $this->is_active && ($this->isRoot() || (bool) $this->parent?->is_active);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('categories.sort_order')->orderBy('categories.id');
    }

    /**
     * Roots first-to-last, each followed by its own subcategories.
     */
    public function scopeHierarchyOrdered(Builder $query): Builder
    {
        return $query
            ->orderByRaw('coalesce((select p.sort_order from categories as p where p.id = categories.parent_id), categories.sort_order)')
            ->orderByRaw('coalesce(categories.parent_id, categories.id)')
            ->orderByRaw('(categories.parent_id is not null)')
            ->ordered();
    }

    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('categories.parent_id');
    }

    public function scopeSubcategories(Builder $query): Builder
    {
        return $query->whereNotNull('categories.parent_id');
    }

    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query
            ->where('categories.is_active', true)
            ->where(fn (Builder $visible) => $visible
                ->whereNull('categories.parent_id')
                ->orWhereHas('parent', fn (Builder $parent) => $parent->where('is_active', true)));
    }

    /**
     * Adds direct_recipes_count (distinct recipes assigned to the category itself) and
     * total_recipes_count (distinct recipes assigned to it or any immediate subcategory).
     * Parent totals use a distinct count so recipes in several categories are counted once.
     */
    public function scopeWithRecipeCounts(Builder $query, bool $publishedOnly = false): Builder
    {
        $prefix = $publishedOnly ? 'published_' : '';

        if ($query->getQuery()->columns === null) {
            $query->select('categories.*');
        }

        return $query->addSelect([
            "{$prefix}direct_recipes_count" => $this->recipeCountSubquery($publishedOnly, includeChildren: false),
            "{$prefix}total_recipes_count" => $this->recipeCountSubquery($publishedOnly, includeChildren: true),
        ]);
    }

    private function recipeCountSubquery(bool $publishedOnly, bool $includeChildren): Builder
    {
        $recipes = $publishedOnly ? Recipe::query()->published() : Recipe::query();

        return $recipes
            ->whereExists(fn ($pivot) => $pivot
                ->from('recipe_category as rc')
                ->whereColumn('rc.recipe_id', 'recipes.id')
                ->where(fn ($match) => $match
                    ->whereColumn('rc.category_id', 'categories.id')
                    ->when($includeChildren, fn ($match) => $match->orWhereIn('rc.category_id', fn ($children) => $children
                        ->from('categories as child_categories')
                        ->select('child_categories.id')
                        ->whereColumn('child_categories.parent_id', 'categories.id')))))
            ->selectRaw('count(*)');
    }
}
