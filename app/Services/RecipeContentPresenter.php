<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeRevision;
use App\Services\Recipes\RecipeStepGroups;
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
     * @return list<array{name: ?string, items: list<array<string, mixed>>}>
     */
    public function ingredientGroups(Recipe $recipe, ?string $servings = null): array
    {
        $lines = $recipe->recipeIngredients()
            ->with(['translations', 'ingredient.translations', 'ingredient.catalogGroup.translations', 'ingredient.publicPrimaryImage.media.translations', 'ingredient.publicPrimaryImage.thumbnail', 'unit.translations', 'ingredientGroup.translations'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $components = $lines->contains(fn (RecipeIngredient $line): bool => $line->line_type === 'recipe')
            ? $this->components($recipe, $servings) : [];

        return $lines
            ->groupBy(fn (RecipeIngredient $line) => $line->ingredient_group_id ?? 0)
            ->sortBy(fn (Collection $group) => $group->first()->ingredientGroup?->sort_order ?? -1)
            ->map(fn (Collection $group): array => [
                'name' => $group->first()->ingredientGroup?->translate()?->name,
                'items' => $group->map(fn (RecipeIngredient $line): array => $line->line_type === 'recipe'
                    ? $this->componentLine($line, $components)
                    : $this->line($recipe, $line, $servings))->values()->all(),
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
        $steps = config('cooking.step_groups_enabled')
            ? app(RecipeStepGroups::class)->orderedSteps($recipe)->loadMissing(['translations', 'media.translations'])
            : ($recipe->relationLoaded('steps') ? $recipe->steps : $recipe->steps()->with(['translations', 'media.translations'])->get());

        return $steps
            ->map(function ($step) use ($locale, $recipe): array {
                $media = $step->relationLoaded('media') ? $step->media : $step->media()->with('translations')->get();
                $media = app(RecipeMediaMetadata::class)->present($recipe, $media);
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
                    'group_id' => config('cooking.step_groups_enabled') ? $step->recipe_step_group_id : null,
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

    /** Nonempty sections share the cooking order; legacy recipes retain one untitled list. */
    public function stepSections(Recipe $recipe, ?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        $steps = collect($this->steps($recipe, $locale));
        $groups = config('cooking.step_groups_enabled') ? $recipe->stepGroups()->with('translations')->get()->keyBy('id') : collect();
        $hasGroups = $steps->contains(fn (array $step): bool => $step['group_id'] !== null);

        return $steps->groupBy(fn (array $step) => $step['group_id'] ?? 'ungrouped')
            ->map(function (Collection $items, string|int $key) use ($groups, $locale, $hasGroups): array {
                $translation = $groups->get($key)?->getTranslation($locale, false);

                return [
                    'id' => $key === 'ungrouped' ? null : (int) $key,
                    'title' => $key === 'ungrouped' ? ($hasGroups ? __('cooking.ungrouped') : null) : ($translation?->title ?: __('cooking.group')),
                    'description' => $translation?->description,
                    'steps' => $items->values()->all(),
                ];
            })->values()->all();
    }

    /** Recipe JSON-LD uses exactly the visible sections and authorized step media. */
    public function instructionSchema(Recipe $recipe, string $locale): array
    {
        $sections = $this->stepSections($recipe, $locale);
        $grouped = collect($sections)->contains(fn (array $section): bool => $section['id'] !== null);
        $result = [];
        foreach ($sections as $index => $section) {
            $steps = collect($section['steps'])->map(fn (array $step, int $position): array => array_filter([
                '@type' => 'HowToStep',
                'position' => $grouped ? $position + 1 : $step['number'],
                'text' => $step['text'],
                'image' => collect($step['images'])->pluck('url')->all(),
                'video' => $step['video'] === null ? null : array_filter([
                    '@type' => 'VideoObject', 'name' => $step['video']['title'],
                    'description' => $step['video']['caption'], 'embedUrl' => $step['video']['embed_url'],
                ], fn ($value) => $value !== null && $value !== ''),
            ], fn ($value) => $value !== null && $value !== []))->all();
            if ($grouped) {
                $result[] = array_filter([
                    '@type' => 'HowToSection', 'position' => $index + 1,
                    'name' => $section['title'], 'description' => $section['description'], 'itemListElement' => $steps,
                ], fn ($value) => $value !== null && $value !== '');
            } else {
                array_push($result, ...$steps);
            }
        }

        return $result;
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

    /** Only resolver-authorized immutable content reaches public nested views. */
    public function components(Recipe|RecipeRevision $recipe, ?string $servings = null): array
    {
        $resolved = app(RecipeComponentResolver::class)->resolve($recipe, auth()->user(), $servings, purpose: 'display');
        abort_if($resolved['unresolved'] !== [], 404);

        $recipe = $recipe instanceof RecipeRevision ? $recipe->recipe : $recipe;

        $ids = collect($resolved['lines'])->pluck('ingredientId')->unique();
        $ingredients = $ids->isEmpty() ? collect() : Ingredient::with('translations')->whereIn('id', $ids)->get()->keyBy('id');

        return collect($resolved['components'])->map(function (array $component) use ($recipe, $ingredients): array {
            $locale = app()->getLocale();
            $component['amount'] = $component['quantity'] === null ? ($component['translations_notes'][$locale]['quantity_text'] ?? null) : $this->formatQuantity($component['quantity']);
            if ($component['quantity_max'] !== null && $component['quantity_max'] !== $component['quantity']) {
                $component['amount'] .= '–'.$this->formatQuantity($component['quantity_max']);
            }
            $component['amount'] = trim(($component['amount'] ?? '').' '.($component['unit']?->code ?? __('nested_public.'.$component['quantity_basis'])));
            $component['summary'] = $component['payload']['translations'][$locale]['summary'] ?? '';
            $component['introduction'] = $component['payload']['translations'][$locale]['introduction'] ?? '';
            $component['note'] = $component['translations_notes'][$locale]['preparation_note'] ?? null;
            $translation = $recipe->getTranslation($locale, false);
            if (blank($translation?->slug) && $locale !== 'en') {
                $translation = $recipe->getTranslation('en', false);
            }
            $component['preview_url'] = filled($translation?->slug) ? route('recipes.component', ['locale' => $translation->locale ?? $locale, 'slug' => $translation->slug, 'path' => $component['path']]) : null;
            $component['steps'] = collect($component['payload']['steps'] ?? [])->map(fn (array $step): array => [
                'number' => $step['step_number'],
                'text' => $step['translations'][$locale]['instruction'] ?? '',
                'minutes' => $step['duration_minutes'] ?? null,
            ])->filter(fn (array $step): bool => $step['text'] !== '')->values()->all();
            $component['ingredients'] = collect($component['lines'])->map(function ($line) use ($ingredients): array {
                $ingredient = $ingredients->get($line->ingredientId);

                $amount = $line->quantity === null ? '' : $this->formatQuantity($line->quantity);
                if ($line->quantityMax !== null && $line->quantityMax !== $line->quantity) {
                    $amount .= '–'.$this->formatQuantity($line->quantityMax);
                }

                return [
                    'name' => (string) ($ingredient?->translate()?->name ?? $ingredient?->code ?? ''),
                    'amount' => trim($amount.' '.($line->unit?->code ?? '')),
                ];
            })->all();
            unset($component['payload'], $component['lines'], $component['translations']);

            return $component;
        })->values()->all();
    }

    /** Place a component at its authored position without treating it as an ingredient. */
    private function componentLine(RecipeIngredient $line, array $components): array
    {
        $component = collect($components)->firstWhere('path', $line->line_uuid);
        abort_if($component === null, 404);

        return [
            'line_type' => 'recipe',
            'line_uuid' => $line->line_uuid,
            'recipe_ingredient_id' => (int) $line->getKey(),
            'ingredient_id' => null,
            'encyclopedia_url' => null,
            'image_url' => null,
            'quantity' => $component['amount'],
            'name' => $component['title'],
            'note' => $component['note'],
            'optional' => (bool) $line->is_optional,
            'components' => array_values(array_filter($components, fn (array $item): bool => $item['path'] === $line->line_uuid
                || str_starts_with($item['path'], $line->line_uuid.'/'))),
        ];
    }

    public function baseServings(Recipe $recipe): int
    {
        return max(1, (int) round((float) $recipe->base_servings));
    }

    public function formatQuantity(string|float $value, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $rounded = round((float) $value, 2);
        if ($rounded == 0.0 && (float) $value != 0.0) {
            $precise = rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');

            return $locale === 'el' ? str_replace('.', ',', $precise) : $precise;
        }
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
     * @return array{recipe_ingredient_id: int, ingredient_id: int, encyclopedia_url: ?string, image_url: ?string, quantity: ?string, name: string, note: ?string, optional: bool}
     */
    public function cookingIngredient(Recipe $recipe, RecipeIngredient $line, string $servings, array $components): array
    {
        return $line->line_type === 'recipe' ? $this->componentLine($line, $components) : $this->line($recipe, $line, $servings);
    }

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

        $encyclopediaUrl = $line->ingredient->encyclopediaUrl(app()->getLocale());

        return [
            'line_type' => 'ingredient',
            'line_uuid' => $line->line_uuid,
            'recipe_ingredient_id' => (int) $line->getKey(),
            'ingredient_id' => (int) $line->ingredient_id,
            'encyclopedia_url' => $encyclopediaUrl,
            'image_url' => $encyclopediaUrl && $line->ingredient->publicPrimaryImage
                ? $line->ingredient->encyclopediaImageUrl(true) : null,
            'quantity' => $amount,
            'name' => ($isPlural && $unit === null ? $ingredient?->plural_name : null) ?: (string) ($ingredient?->name ?? $line->ingredient->code),
            'note' => filled($translation?->preparation_note) ? $translation->preparation_note : null,
            'optional' => (bool) $line->is_optional,
        ];
    }
}
