<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\Unit;

/**
 * Human-readable differences between two revision payloads, for the review screen. Row IDs and display order
 * are not changes; every difference shown is something a reader of the recipe could see.
 */
class RecipeRevisionComparer
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<array{section: string, label: string, before: ?string, after: ?string}>
     */
    public function compare(array $before, array $after): array
    {
        return [
            ...$this->translations($before, $after),
            ...$this->details($before, $after),
            ...$this->ingredients($before, $after),
            ...$this->steps($before, $after),
            ...$this->lists($before, $after),
        ];
    }

    /**
     * @return list<array{section: string, label: string, before: ?string, after: ?string}>
     */
    private function translations(array $before, array $after): array
    {
        $changes = [];

        foreach (array_unique([...array_keys($before['translations'] ?? []), ...array_keys($after['translations'] ?? [])]) as $locale) {
            foreach (['title', 'slug', 'summary', 'introduction', 'seo_title', 'seo_description'] as $field) {
                $this->add($changes, 'Text ('.strtoupper($locale).')', ucfirst(str_replace('_', ' ', $field)), $before['translations'][$locale][$field] ?? null, $after['translations'][$locale][$field] ?? null);
            }
        }

        return $changes;
    }

    private function details(array $before, array $after): array
    {
        $changes = [];

        foreach (['prep_minutes', 'cook_minutes', 'rest_minutes', 'base_servings', 'difficulty'] as $field) {
            $this->add($changes, 'Details', ucfirst(str_replace('_', ' ', $field)), $before['recipe'][$field] ?? null, $after['recipe'][$field] ?? null);
        }

        return $changes;
    }

    private function ingredients(array $before, array $after): array
    {
        $changes = [];
        $old = collect($before['ingredients'] ?? [])->keyBy(fn (array $line): string => $this->lineKey($line));
        $new = collect($after['ingredients'] ?? [])->keyBy(fn (array $line): string => $this->lineKey($line));

        foreach ($old->diffKeys($new) as $line) {
            $this->add($changes, 'Ingredients', $this->ingredientName($line), $this->describeLine($line), null);
        }

        foreach ($new->diffKeys($old) as $line) {
            $this->add($changes, 'Ingredients', $this->ingredientName($line), null, $this->describeLine($line));
        }

        foreach ($new->intersectByKeys($old) as $key => $line) {
            $this->add($changes, 'Ingredients', $this->ingredientName($line), $this->describeLine($old[$key]), $this->describeLine($line));
        }

        return $changes;
    }

    private function steps(array $before, array $after): array
    {
        $changes = [];
        $old = collect($before['steps'] ?? [])->sortBy('step_number')->values();
        $new = collect($after['steps'] ?? [])->sortBy('step_number')->values();

        foreach (range(0, max($old->count(), $new->count()) - 1) as $index) {
            if ($index < 0) {
                break;
            }

            $this->add($changes, 'Steps', 'Step '.($index + 1), $this->describeStep($old[$index] ?? null), $this->describeStep($new[$index] ?? null));
        }

        return $changes;
    }

    private function lists(array $before, array $after): array
    {
        $changes = [];

        foreach (['category_ids' => 'Categories', 'tag_ids' => 'Tags'] as $key => $label) {
            $this->add($changes, 'Classification', $label, implode(', ', $before[$key] ?? []) ?: null, implode(', ', $after[$key] ?? []) ?: null);
        }

        $media = fn (array $payload): string => collect($payload['media'] ?? [])->map(fn (array $item): string => "{$item['role']} #{$item['media_id']}")->implode(', ');
        $this->add($changes, 'Media', 'Recipe media', $media($before) ?: null, $media($after) ?: null);

        return $changes;
    }

    private function lineKey(array $line): string
    {
        return ($line['ingredient_id'] ?? 0).'|'.($line['id'] ?? 'new-'.md5(json_encode($line)));
    }

    private function ingredientName(array $line): string
    {
        $ingredient = Ingredient::query()->with('translations')->find($line['ingredient_id'] ?? 0);

        return $ingredient?->translate('en')?->name ?? 'Ingredient #'.($line['ingredient_id'] ?? '?');
    }

    private function describeLine(array $line): string
    {
        $unit = isset($line['unit_id']) ? Unit::query()->find($line['unit_id'])?->code : null;
        $quantity = $line['quantity'] ?? null;

        if ($quantity !== null && ($line['quantity_max'] ?? null) !== null) {
            $quantity .= '–'.$line['quantity_max'];
        }

        $notes = collect($line['translations'] ?? [])->map(fn (array $row, string $locale): ?string => filled($row['preparation_note'] ?? null) ? strtoupper($locale).': '.$row['preparation_note'] : null)->filter()->implode('; ');

        return trim(implode(' ', array_filter([$quantity, $unit, ($line['is_optional'] ?? false) ? '(optional)' : null, $notes !== '' ? "— {$notes}" : null])));
    }

    private function describeStep(?array $step): ?string
    {
        if ($step === null) {
            return null;
        }

        return collect($step['translations'] ?? [])->map(fn (array $row, string $locale): string => strtoupper($locale).': '.($row['instruction'] ?? ''))->implode("\n");
    }

    /**
     * @param  list<array{section: string, label: string, before: ?string, after: ?string}>  $changes
     */
    private function add(array &$changes, string $section, string $label, mixed $before, mixed $after): void
    {
        $before = $before === null || $before === '' ? null : (string) $before;
        $after = $after === null || $after === '' ? null : (string) $after;

        if ($before !== $after) {
            $changes[] = compact('section', 'label', 'before', 'after');
        }
    }
}
