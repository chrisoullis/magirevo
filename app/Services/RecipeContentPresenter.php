<?php

namespace App\Services;

use App\Models\Media;
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

    /** Introductions longer than this push the method below the first screen, so a jump link is offered. */
    public const LONG_INTRODUCTION_CHARACTERS = 600;

    public function __construct(private readonly ServingAdjustmentService $servingAdjustment) {}

    /**
     * Ingredient groups ready for display, scaled to the requested servings when given.
     *
     * @return list<array{name: ?string, items: list<array{recipe_ingredient_id: int, ingredient_id: int, quantity: ?string, name: string, note: ?string, optional: bool}>}>
     */
    public function ingredientGroups(Recipe $recipe, ?string $servings = null): array
    {
        $lines = $recipe->recipeIngredients()
            ->with(['translations', 'ingredient.translations', 'unit.translations', 'ingredientGroup.translations'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

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
     * @return list<array{number: int, text: string, minutes: ?int, images: list<array{url: string, alt_text: string, caption: ?string, width: ?int, height: ?int}>, video: ?array{embed_url: string, title: string, caption: ?string}>
     */
    public function steps(Recipe $recipe, ?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        $steps = $recipe->relationLoaded('steps')
            ? $recipe->steps
            : $recipe->steps()->with(['translations', 'media.translations'])->get();

        return $steps
            ->map(function ($step) use ($locale): array {
                $media = $step->relationLoaded('media') ? $step->media : $step->media()->with('translations')->get();
                $images = $media
                    ->filter(fn (Media $item): bool => $item->pivot->kind === 'image' && $item->media_type === Media::TYPE_IMAGE)
                    ->map(fn (Media $item): array => [
                        'url' => $item->publicUrl(),
                        'alt_text' => (string) ($item->getTranslation($locale, false)?->alt_text ?? ''),
                        'caption' => $item->getTranslation($locale, false)?->caption,
                        'width' => $item->width,
                        'height' => $item->height,
                    ])
                    ->values()
                    ->all();
                $video = $media->first(fn (Media $item): bool => $item->pivot->kind === 'video' && $item->media_type === Media::TYPE_VIDEO);
                $videoTranslation = $video?->getTranslation($locale, false);

                return [
                    'number' => $step->step_number,
                    'text' => (string) $step->getTranslation($locale, false)?->instruction,
                    'minutes' => $step->duration_minutes,
                    'images' => $images,
                    'video' => $video === null ? null : [
                        'embed_url' => (string) $video->url,
                        'title' => (string) $videoTranslation?->alt_text,
                        'caption' => $videoTranslation?->caption,
                    ],
                ];
            })
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
     * @return array{recipe_ingredient_id: int, ingredient_id: int, quantity: ?string, name: string, note: ?string, optional: bool}
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
            'recipe_ingredient_id' => (int) $line->getKey(),
            'ingredient_id' => (int) $line->ingredient_id,
            'quantity' => $amount,
            'name' => ($isPlural && $unit === null ? $ingredient?->plural_name : null) ?: (string) ($ingredient?->name ?? $line->ingredient->code),
            'note' => filled($translation?->preparation_note) ? $translation->preparation_note : null,
            'optional' => (bool) $line->is_optional,
        ];
    }
}
