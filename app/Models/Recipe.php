<?php

namespace App\Models;

use App\Services\RecipePublishingService;
use Astrotomic\Translatable\Translatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Recipe extends Model
{
    use HasFactory;
    use SoftDeletes;
    use Translatable;

    protected $fillable = [
        'author_id', 'status', 'category', 'prep_minutes', 'cook_minutes', 'rest_minutes',
        'base_servings', 'servings', 'difficulty', 'image_url', 'ingredients', 'instructions',
        'is_featured', 'published_at',
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
        static::saving(function (self $recipe): void {
            if ($recipe->status !== 'published') {
                return;
            }

            app(RecipePublishingService::class)->validateForPublishing($recipe);
        });
    }

    protected function casts(): array
    {
        return [
            'ingredients' => 'array',
            'instructions' => 'array',
            'prep_minutes' => 'integer',
            'cook_minutes' => 'integer',
            'rest_minutes' => 'integer',
            'base_servings' => 'decimal:2',
            'is_featured' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function savedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
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

    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('status', 'published')
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

    public function scopeInCategory(Builder $query, string $categoryCode): Builder
    {
        return $query->whereHas('categories', fn (Builder $category) => $category->where('categories.code', $categoryCode));
    }

    public function coverMedia(): ?Media
    {
        $media = $this->relationLoaded('media') ? $this->media : $this->media()->with('translations')->get();

        return $media->first(fn (Media $item): bool => $item->pivot->role === 'cover') ?? $media->first();
    }

    public function coverUrl(): string
    {
        $cover = $this->coverMedia();

        return $cover
            ? Storage::disk($cover->disk)->url($cover->file_path)
            : (string) $this->image_url;
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

        $key = 'site.difficulty.'.Str::snake(Str::slug($this->difficulty, '_'));

        if (Lang::has($key)) {
            return __($key);
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
        return $this->primaryCategory()?->translate()?->name ?? (string) $this->category;
    }
}
