<?php

namespace App\Models;

use Astrotomic\Translatable\Translatable;
use Database\Factories\DifficultyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The code is the value stored in recipes.difficulty.
 */
class Difficulty extends Model
{
    /** @use HasFactory<DifficultyFactory> */
    use HasFactory;

    use Translatable;

    protected $fillable = [
        'code',
        'is_active',
        'sort_order',
    ];

    public array $translatedAttributes = [
        'name',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function recipes(): HasMany
    {
        return $this->hasMany(Recipe::class, 'difficulty', 'code');
    }

    public function isInUse(): bool
    {
        return $this->recipes()->withTrashed()->exists();
    }

    /**
     * Active difficulties keyed by code, plus the given code so an inactive value stays selectable.
     *
     * @return array<string, string>
     */
    public static function options(?string $includeCode = null): array
    {
        return static::query()
            ->with('translations')
            ->where(fn (Builder $query) => $query
                ->where('is_active', true)
                ->when(filled($includeCode), fn (Builder $query) => $query->orWhere('code', $includeCode)))
            ->orderBy('sort_order')
            ->get()
            ->mapWithKeys(fn (self $difficulty): array => [$difficulty->code => $difficulty->translate('en')?->name ?? $difficulty->code])
            ->all();
    }
}
