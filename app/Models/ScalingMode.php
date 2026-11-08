<?php

namespace App\Models;

use App\Models\Concerns\HasImmutableCode;
use Astrotomic\Translatable\Translatable;
use Database\Factories\ScalingModeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The code is the value stored in recipe_ingredients.scaling_mode.
 * Only the "linear" code is scaled automatically by ServingAdjustmentService.
 */
class ScalingMode extends Model
{
    /** @use HasFactory<ScalingModeFactory> */
    use HasFactory;

    use HasImmutableCode;
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

    public function recipeIngredients(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class, 'scaling_mode', 'code');
    }

    public function isInUse(): bool
    {
        return $this->recipeIngredients()->exists();
    }

    /**
     * Active scaling modes keyed by code, plus the given code so an inactive value stays selectable.
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
            ->mapWithKeys(fn (self $mode): array => [$mode->code => $mode->translate(app()->getLocale())?->name ?? $mode->code])
            ->all();
    }
}
