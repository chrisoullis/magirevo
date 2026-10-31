<?php

namespace App\Services;

use App\Models\Recipe;
use App\Models\RecipeIngredient;
use Illuminate\Support\Collection;

class RecipeContentPresenter
{
    private const FRACTIONS = [
        '0.25' => '¼',
        '0.33' => '⅓',
        '0.50' => '½',
        '0.67' => '⅔',
        '0.75' => '¾',
    ];

    public function __construct(private readonly ServingAdjustmentService $servingAdjustment) {}

    /**
     * Ingredient groups ready for display, scaled to the requested servings when given.
     * Recipes without structured ingredients fall back to the legacy free-text list.
     *
     * @return list<array{name: ?string, items: list<array{quantity: ?string, name: string, note: ?string, optional: bool}>}>
     */
    public function ingredientGroups(Recipe $recipe, ?string $servings = null): array
    {
        $lines = $recipe->recipeIngredients()
            ->with(['translations', 'ingredient.translations', 'unit.translations', 'ingredientGroup.translations'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($lines->isEmpty()) {
            return [[
                'name' => null,
                'items' => collect($recipe->ingredients ?? [])
                    ->map(fn (string $text): array => ['quantity' => null, 'name' => $text, 'note' => null, 'optional' => false])
                    ->all(),
            ]];
        }

        return $lines
            ->groupBy(fn (RecipeIngredient $line) => $line->ingredient_group_id ?? 0)
            ->sortBy(fn (Collection $group) => $group->first()->ingredientGroup?->sort_order ?? -1)
            ->map(fn (Collection $group): array => [
                'name' => $group->first()->ingredientGroup?->translate()?->name,
                'items' => $group->map(fn (RecipeIngredient $line): array => $this->line($recipe, $line, $servings))->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{text: string, minutes: ?int}>
     */
    public function steps(Recipe $recipe): array
    {
        $steps = $recipe->steps()->with('translations')->get();

        if ($steps->isEmpty()) {
            return collect($recipe->instructions ?? [])
                ->map(fn (string $text): array => ['text' => $text, 'minutes' => null])
                ->all();
        }

        return $steps
            ->map(fn ($step): array => ['text' => (string) $step->translate()?->instruction, 'minutes' => $step->duration_minutes])
            ->filter(fn (array $step): bool => $step['text'] !== '')
            ->values()
            ->all();
    }

    /**
     * Plain-text ingredient lines at the recipe's original yield, for structured data.
     *
     * @return list<string>
     */
    public function plainIngredients(Recipe $recipe): array
    {
        return collect($this->ingredientGroups($recipe))
            ->flatMap(fn (array $group) => $group['items'])
            ->map(fn (array $item): string => trim(implode(' ', array_filter([$item['quantity'], $item['name']])).($item['note'] ? ', '.$item['note'] : '')))
            ->all();
    }

    public function baseServings(Recipe $recipe): int
    {
        return max(1, (int) round((float) $recipe->base_servings));
    }

    public function formatQuantity(string|float $value, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $rounded = round((float) $value, 2);
        $whole = (int) floor($rounded);
        $fraction = number_format($rounded - $whole, 2, '.', '');

        if ($fraction === '0.00') {
            return (string) $whole;
        }

        if (isset(self::FRACTIONS[$fraction])) {
            return ($whole > 0 ? $whole : '').self::FRACTIONS[$fraction];
        }

        $formatted = rtrim(rtrim(number_format($rounded, 2, '.', ''), '0'), '.');

        return $locale === 'el' ? str_replace('.', ',', $formatted) : $formatted;
    }

    /**
     * @return array{quantity: ?string, name: string, note: ?string, optional: bool}
     */
    private function line(Recipe $recipe, RecipeIngredient $line, ?string $servings): array
    {
        $line->setRelation('recipe', $recipe);

        $quantity = $line->quantity;
        $quantityMax = $line->quantity_max;

        if ($servings !== null && $quantity !== null && (float) $servings !== (float) $recipe->base_servings) {
            $adjusted = $this->servingAdjustment->adjust($line, $servings);
            $quantity = $adjusted['quantity'];
            $quantityMax = $adjusted['quantity_max'];
        }

        $translation = $line->translate();
        $ingredient = $line->ingredient->translate();
        $unit = $line->unit?->translate()?->abbreviation;
        $isPlural = $quantity !== null && (float) $quantity !== 1.0;

        $amount = null;

        if ($quantity !== null) {
            $amount = $this->formatQuantity($quantity);

            if ($quantityMax !== null && (float) $quantityMax !== (float) $quantity) {
                $amount .= '–'.$this->formatQuantity($quantityMax);
            }

            $amount = trim($amount.' '.$unit);
        } elseif (filled($translation?->quantity_text)) {
            $amount = $translation->quantity_text;
        }

        return [
            'quantity' => $amount,
            'name' => ($isPlural && $unit === null ? $ingredient?->plural_name : null) ?: (string) ($ingredient?->name ?? $line->ingredient->code),
            'note' => filled($translation?->preparation_note) ? $translation->preparation_note : null,
            'optional' => (bool) $line->is_optional,
        ];
    }
}
