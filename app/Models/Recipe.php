<?php

namespace App\Models;

use App\Enums\RecipeStatus;
use App\Enums\RecipeVisibility;
use App\Services\RecipeMediaMetadata;
use App\Services\RecipePublishingService;
use App\Services\RecipeQuotaService;
use App\Support\AppPermissions;
use Astrotomic\Translatable\Translatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class Recipe extends Model
{
    use HasFactory;
    use SoftDeletes;
    use Translatable;

    protected $attributes = ['visibility' => 'private'];

    protected $fillable = [
        'author_id', 'status', 'visibility', 'prep_minutes', 'cook_minutes', 'rest_minutes',
        'base_servings', 'difficulty', 'is_featured', 'published_at',
    ];

    public array $translatedAttributes = [
        'title',
        'slug',
        'summary',
        'introduction',
        'seo_title',
        'seo_description',
    ];

    protected static function booted(): void
    {
        // Restoring a deleted recipe adds to counted usage, so it is checked like a creation when the owner does it.
        static::restoring(function (self $recipe): bool {
            if ($recipe->visibility === RecipeVisibility::Public || $recipe->author === null || (int) Auth::id() !== (int) $recipe->author_id) {
                return true;
            }

            $quota = app(RecipeQuotaService::class);

            if ($quota->hasReachedLimit($recipe->author)) {
                throw ValidationException::withMessages(['quota' => __('quota.errors.recipes_reached', ['limit' => $quota->personalRecipeLimit($recipe->author)])]);
            }

            return true;
        });

        static::saving(function (self $recipe): void {
            if ($recipe->visibility === RecipeVisibility::Public && $recipe->status !== RecipeStatus::Published->value) {
                throw ValidationException::withMessages(['visibility' => 'Only published recipes can be public.']);
            }

            if ($recipe->status !== 'published') {
                return;
            }

            app(RecipePublishingService::class)->validateForPublishing($recipe);
        });
    }

    protected function casts(): array
    {
        return [
            'prep_minutes' => 'integer',
            'cook_minutes' => 'integer',
            'rest_minutes' => 'integer',
            'base_servings' => 'decimal:2',
            'is_featured' => 'boolean',
            'published_at' => 'datetime',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'visibility' => RecipeVisibility::class,
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(RecipeRevision::class)->orderByDesc('revision_number');
    }

    /**
     * The approved revision whose content the live recipe tables currently hold.
     */
    public function currentRevision(): BelongsTo
    {
        return $this->belongsTo(RecipeRevision::class, 'current_revision_id');
    }

    public function favoritedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'recipe_favorites')->withTimestamps();
    }

    public function ingredientGroups(): HasMany
    {
        return $this->hasMany(IngredientGroup::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'recipe_category')->withTimestamps();
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'recipe_tag')->withTimestamps();
    }

    public function recipeIngredients(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class);
    }

    public function personalNotes(): HasMany
    {
        return $this->hasMany(UserRecipeNote::class);
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(RecipeRating::class);
    }

    public function personalIngredientChanges(): HasMany
    {
        return $this->hasMany(UserRecipeIngredientChange::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(RecipeStep::class)->orderBy('step_number');
    }

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'recipe_media')
            ->withPivot(['role', 'sort_order'])
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }

    public function shares(): HasMany
    {
        return $this->hasMany(RecipeShare::class);
    }

    /**
     * Recipients of active grants. Grants only count while the recipe itself is shared.
     */
    public function sharedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'recipe_shares')
            ->wherePivotNull('revoked_at')
            ->withPivot(['permission', 'shared_by'])
            ->withTimestamps();
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Anonymous visitors may read a recipe only when it is public, published, live and not deleted.
     */
    public function isPubliclyAccessible(): bool
    {
        return $this->visibility === RecipeVisibility::Public
            && $this->status === RecipeStatus::Published->value
            && $this->published_at !== null
            && ! $this->published_at->isFuture()
            && ! $this->trashed();
    }

    /**
     * Recipes an administrative user may list in the panel: their own, the editorial pool
     * (public or awaiting review), or everything for users with explicit private-moderation access.
     */
    public function scopeManageableBy(Builder $query, User $user): Builder
    {
        if ($user->can(AppPermissions::RECIPES_MODERATE_PRIVATE)) {
            return $query;
        }

        return $query->where(fn (Builder $recipes) => $recipes
            ->where('author_id', $user->getKey())
            ->orWhere('visibility', RecipeVisibility::Public->value)
            ->orWhere('status', RecipeStatus::Pending->value));
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('status', 'published')
            ->where('visibility', RecipeVisibility::Public->value)
            ->where('published_at', '<=', now())
            ->whereNotNull('published_at')
            ->whereHas('translations', fn (Builder $translation) => $translation
                ->where('locale', 'en')
                ->where('title', '<>', '')
                ->where('slug', '<>', ''))
            ->whereHas('translations', fn (Builder $translation) => $translation
                ->where('locale', 'el')
                ->where('title', '<>', '')
                ->where('slug', '<>', ''));
    }

    /**
     * Recipes that use personal quota: everything not public (public implies published).
     */
    public function scopeCountedAgainstQuota(Builder $query): Builder
    {
        return $query->where('visibility', '<>', RecipeVisibility::Public->value);
    }

    public function scopeInCategory(Builder $query, string $categoryCode): Builder
    {
        return $query->whereHas('categories', fn (Builder $category) => $category
            ->where('categories.code', $categoryCode)
            ->orWhereHas('parent', fn (Builder $parent) => $parent->where('code', $categoryCode)));
    }

    /**
     * The translation to show a viewer: their locale if it has content, otherwise whichever language exists.
     */
    public function displayTranslation(string $locale): ?RecipeTranslation
    {
        $has = fn (?RecipeTranslation $translation): bool => $translation !== null && filled($translation->slug) && filled($translation->title);

        $preferred = $this->getTranslation($locale, false);

        if ($has($preferred)) {
            return $preferred;
        }

        return $this->translations->first($has);
    }

    public function coverMedia(): ?Media
    {
        $media = $this->relationLoaded('media') ? $this->media : $this->media()->with('translations')->get();

        $media = app(RecipeMediaMetadata::class)->present($this, $media);

        return $media->first(fn (Media $item): bool => $item->pivot->role === 'cover') ?? $media->first();
    }

    public function coverUrl(): string
    {
        $cover = $this->coverMedia();

        return $cover?->publicUrl() ?? asset('images/recipe-placeholder.svg');
    }

    public function coverAlt(): string
    {
        return $this->coverMedia()?->translate()?->alt_text ?: (string) $this->title;
    }

    public function totalMinutes(): int
    {
        return $this->prep_minutes + $this->cook_minutes;
    }

    public function difficultyLabel(): ?string
    {
        if (blank($this->difficulty)) {
            return null;
        }

        $name = Difficulty::query()->where('code', $this->difficulty)->first()?->translate(app()->getLocale())?->name;

        return $name ?? $this->difficulty;
    }

    public function primaryCategory(): ?Category
    {
        $categories = $this->relationLoaded('categories') ? $this->categories : $this->categories()->with('translations')->get();

        return $categories->sortBy('sort_order')->first();
    }

    public function categoryLabel(): string
    {
        return $this->primaryCategory()?->translate()?->name ?? '';
    }
}
