<?php

namespace App\Models;

use Astrotomic\Translatable\Translatable;
use Database\Factories\IngredientFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

class Ingredient extends Model
{
    /** @use HasFactory<IngredientFactory> */
    use HasFactory;

    use Translatable;

    protected $fillable = [
        'code',
        'catalog_group_id',
        'default_unit_id',
        'is_active',
    ];

    public array $translatedAttributes = [
        'name',
        'plural_name',
        'description',
        'slug',
        'culinary_uses',
        'preparation_tips',
        'storage_tips',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function defaultUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'default_unit_id');
    }

    public function recipeIngredients(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class);
    }

    public function catalogGroup(): BelongsTo
    {
        return $this->belongsTo(IngredientCatalogGroup::class, 'catalog_group_id');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(IngredientAlias::class);
    }

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'ingredient_media')->using(IngredientMedia::class)
            ->withPivot(['id', 'role', 'sort_order', 'thumbnail_media_id', 'rights_basis', 'rights_reference', 'rights_confirmed_by', 'rights_confirmed_at'])
            ->withTimestamps();
    }

    public function primaryImage(): HasOne
    {
        return $this->hasOne(IngredientMedia::class)->where('role', 'primary');
    }

    public function imageAssignments(): HasMany
    {
        return $this->hasMany(IngredientMedia::class);
    }

    public function scopePubliclyVisible(Builder $query, string $locale): Builder
    {
        return $query->where('ingredients.is_active', true)
            ->whereHas('catalogGroup', fn (Builder $group) => $group->where('is_active', true)
                ->whereHas('translations', fn (Builder $translation) => $translation->where('locale', $locale)->whereRaw('length(btrim(name)) > 0')))
            ->whereHas('translations', fn (Builder $translation) => $translation->where('locale', $locale)
                ->whereRaw('length(btrim(name)) > 0')->whereNotNull('slug')->whereRaw('length(btrim(slug)) > 0'));
    }

    public function hasPublicTranslation(string $locale): bool
    {
        return $this->is_active && $this->catalogGroup?->is_active
            && filled($this->catalogGroup->getTranslation($locale, false)?->name)
            && filled($this->getTranslation($locale, false)?->name)
            && filled($this->getTranslation($locale, false)?->slug);
    }

    public function scopeSearchCatalogue(Builder $query, string $term): Builder
    {
        $term = trim(mb_substr($term, 0, 100));
        if ($term === '') {
            return $query;
        }
        $fold = (bool) DB::scalar("select to_regprocedure('f_unaccent(text)') is not null");
        $pattern = '%'.addcslashes(mb_strtolower($term), '%_\\').'%';
        // PostgreSQL databases initialized with the C locale do not lowercase Greek.
        $normalized = "translate(lower(%s), 'ΑΒΓΔΕΖΗΘΙΚΛΜΝΞΟΠΡΣΤΥΦΧΨΩΆΈΉΊΌΎΏΪΫ', 'αβγδεζηθικλμνξοπρστυφχψωάέήίόύώϊϋ')";
        $expression = $fold ? 'f_unaccent('.$normalized.') LIKE f_unaccent(?)' : $normalized.' LIKE ?';

        return $query->where(fn (Builder $match) => $match->where('code', 'ilike', $pattern)
            ->orWhereHas('translations', fn (Builder $translation) => $translation->whereIn('locale', ['en', 'el'])
                ->where(fn (Builder $name) => $name->whereRaw(sprintf($expression, 'name'), [$pattern])->orWhereRaw(sprintf($expression, 'plural_name'), [$pattern])))
            ->orWhereHas('aliases', fn (Builder $alias) => $alias->whereIn('locale', ['en', 'el'])->whereRaw(sprintf($expression, 'alias'), [$pattern])));
    }

    public function encyclopediaUrl(string $locale): ?string
    {
        return $this->hasPublicTranslation($locale)
            ? route('ingredients.show', ['locale' => $locale, 'slug' => $this->getTranslation($locale, false)->slug], false)
            : null;
    }

    public function publicPrimaryImage(): HasOne
    {
        return $this->hasOne(IngredientMedia::class)->where('role', 'primary')->publiclyUsable();
    }

    public function encyclopediaImageUrl(bool $thumbnail = false): string
    {
        return $this->publicPrimaryImage?->publicUrl($thumbnail ? 'thumbnail' : 'display') ?? asset('images/ingredient-placeholder.svg');
    }

    public function encyclopediaImageAlt(string $locale): string
    {
        return $this->publicPrimaryImage?->media->getTranslation($locale, false)?->alt_text ?? $this->getTranslation($locale, false)?->name ?? $this->code;
    }

    public function imageUrl(bool $thumbnail = false): string
    {
        $image = $this->primaryImage;

        return ($thumbnail ? ($image?->thumbnail ?? $image?->media) : $image?->media)?->publicUrl()
            ?? asset('images/ingredient-placeholder.svg');
    }

    public function imageAlt(?string $locale = null): string
    {
        return $this->primaryImage?->media?->translate($locale)?->alt_text ?? $this->translate($locale)?->name ?? $this->code;
    }

    public function dietaryProfile(): HasOne
    {
        return $this->hasOne(IngredientDietaryProfile::class);
    }

    public function dietaryAssessments(): HasMany
    {
        return $this->hasMany(IngredientDietaryAssessment::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(IngredientComponent::class);
    }

    public function allergenAssessments(): HasMany
    {
        return $this->hasMany(IngredientAllergenAssessment::class);
    }

    public function substitutions(): HasMany
    {
        return $this->hasMany(IngredientSubstitution::class);
    }

    public function substitutionSuggestions(): HasMany
    {
        return $this->hasMany(IngredientSubstitution::class, 'replacement_ingredient_id');
    }

    public function personalRecipeIngredientChanges(): HasMany
    {
        return $this->hasMany(UserRecipeIngredientChange::class, 'alternative_ingredient_id');
    }

    public function isInUse(): bool
    {
        return $this->recipeIngredients()->exists()
            || RecipeIngredient::query()->where('prepared_ingredient_id', $this->id)->exists()
            || $this->substitutions()->exists()
            || $this->substitutionSuggestions()->exists()
            || $this->personalRecipeIngredientChanges()->exists()
            || IngredientComponent::query()->where('component_ingredient_id', $this->id)->exists()
            || RecipeRevision::query()->whereRaw("payload->'ingredients' @> ?::jsonb", [json_encode([['ingredient_id' => (int) $this->id]])])->exists();
    }
}
