<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Shared public discovery matching; visibility remains the caller's query scope. */
class RecipeSearch
{
    private ?bool $accentFolding = null;

    public function recipes(Builder $query, string $term): Builder
    {
        $term = trim(mb_substr($term, 0, 100));
        if ($term === '') {
            return $query;
        }

        return $query->where(fn (Builder $match) => $match
            ->whereHas('translations', fn (Builder $texts) => $this->text($texts, ['title', 'summary', 'introduction'], $term))
            ->orWhereHas('categories', fn (Builder $categories) => $categories->publiclyVisible()
                ->whereHas('translations', fn (Builder $texts) => $this->text($texts, ['name'], $term)))
            ->orWhereHas('tags', fn (Builder $tags) => $tags->where('is_active', true)->editorial()
                ->whereHas('translations', fn (Builder $texts) => $this->text($texts, ['name'], $term)))
            ->orWhereHas('recipeIngredients.ingredient', fn (Builder $ingredients) => $ingredients->where('is_active', true)->searchCatalogue($term)));
    }

    public function text(Builder $query, array $columns, string $term, bool $prefix = false, ?string $locale = null): Builder
    {
        $this->accentFolding ??= DB::getDriverName() === 'pgsql' && (bool) DB::scalar("select to_regproc('f_unaccent') is not null");
        $like = ($prefix ? '' : '%').addcslashes(mb_strtolower(trim(mb_substr($term, 0, 100))), '%_\\').'%';

        return $query->whereIn('locale', $locale === null ? config('translatable.locales') : [$locale])->where(function (Builder $match) use ($columns, $like): void {
            foreach ($columns as $column) {
                // Column names are internal constants supplied by the search service, never request input.
                $expression = "translate(lower({$column}), 'ΑΒΓΔΕΖΗΘΙΚΛΜΝΞΟΠΡΣΤΥΦΧΨΩΆΈΉΊΌΎΏΪΫ', 'αβγδεζηθικλμνξοπρστυφχψωάέήίόύώϊϋ')";
                $match->orWhereRaw($this->accentFolding ? "f_unaccent({$expression}) LIKE f_unaccent(?)" : "{$expression} LIKE ?", [$like]);
            }
        });
    }

    public function collection(Builder $query, string $collection): Builder
    {
        if (! in_array($collection, ['local', 'world', 'seasonal'], true)) {
            return $query;
        }
        $season = match (true) {
            now()->month >= 3 && now()->month <= 5 => 'spring',
            now()->month >= 6 && now()->month <= 8 => 'summer',
            now()->month >= 9 && now()->month <= 11 => 'autumn',
            default => 'winter',
        };

        return $query->whereHas('tags', fn (Builder $tags) => $tags->where('is_active', true)->editorial()
            ->when($collection === 'local', fn ($tags) => $tags->where('group_code', 'cuisine')->whereIn('code', ['greek', 'cypriot']))
            ->when($collection === 'world', fn ($tags) => $tags->where('group_code', 'cuisine')->whereNotIn('code', ['greek', 'cypriot']))
            ->when($collection === 'seasonal', fn ($tags) => $tags->where('group_code', 'season')->whereIn('code', [$season, 'seasonal-produce'])));
    }
}
