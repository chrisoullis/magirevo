<?php

namespace App\Models;

use App\Enums\TagClassification;
use App\Enums\TagGroup;
use Astrotomic\Translatable\Translatable;
use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory;

    use Translatable;

    protected $fillable = [
        'code',
        'group_code',
        'classification',
        'is_public_enabled',
        'is_active',
        'sort_order',
    ];

    public array $translatedAttributes = [
        'name',
        'slug',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'classification' => TagClassification::class,
            'is_public_enabled' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function dietaryClaims(): HasMany
    {
        return $this->hasMany(DietaryClaim::class);
    }

    public function groupLabel(?string $locale = null): string
    {
        return TagGroup::tryFrom($this->group_code ?? '')?->label($locale) ?? __('tags.other', [], $locale);
    }

    public function isEditorial(): bool
    {
        return $this->classification->isFreelyPublic() && $this->group_code !== TagGroup::DietaryClaim->value;
    }

    public function scopeEditorial(Builder $query): Builder
    {
        return $query->whereIn('classification', TagClassification::freelyPublicValues())
            ->where(fn (Builder $group) => $group->whereNull('group_code')->orWhere('group_code', '!=', TagGroup::DietaryClaim->value));
    }

    public function isDietaryClaim(): bool
    {
        return $this->classification->isDietaryClaim();
    }

    public function recipes(): BelongsToMany
    {
        return $this->belongsToMany(Recipe::class, 'recipe_tag')->withTimestamps();
    }

    public function isInUse(): bool
    {
        return $this->recipes()->withTrashed()->exists();
    }
}
