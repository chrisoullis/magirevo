<?php

namespace App\Services\Recipes;

use App\Models\Media;
use App\Models\Recipe;
use App\Models\Tag;
use App\Services\RecipeRevisionSnapshotter;

class RecipeEditorData
{
    private const LOCALES = ['en' => 'English', 'el' => 'Ελληνικά'];

    public static function fromRecipe(Recipe $recipe): array
    {
        $data = self::fromPayload(app(RecipeRevisionSnapshotter::class)->serialize($recipe->id));
        $mediaByStep = $recipe->steps()->with('media.translations')->get()->keyBy('id');
        foreach ($data['steps_data'] as &$step) {
            $media = $mediaByStep[$step['id']]->media;
            $step['media_items'] = $media->filter(fn ($item): bool => $item->pivot->kind === 'image' && $item->media_type === Media::TYPE_IMAGE)
                ->map(fn ($item): array => [
                    'media_id' => $item->id,
                    'alt_text_en' => $item->getTranslation('en', false)?->alt_text,
                    'caption_en' => $item->getTranslation('en', false)?->caption,
                    'alt_text_el' => $item->getTranslation('el', false)?->alt_text,
                    'caption_el' => $item->getTranslation('el', false)?->caption,
                ])->values()->all();
            $video = $media->first(fn ($item): bool => $item->pivot->kind === 'video' && $item->media_type === Media::TYPE_VIDEO);
            $step['video_url'] = $video?->url;
            foreach (['en', 'el'] as $locale) {
                $step['video_alt_text_'.$locale] = $video?->getTranslation($locale, false)?->alt_text;
                $step['video_caption_'.$locale] = $video?->getTranslation($locale, false)?->caption;
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function fromPayload(array $payload): array
    {
        $data = [];

        foreach (array_keys(self::LOCALES) as $locale) {
            $data[$locale] = $payload['translations'][$locale] ?? [];
        }

        foreach (['prep_minutes', 'cook_minutes', 'rest_minutes', 'base_servings', 'difficulty'] as $field) {
            $data[$field] = $payload['recipe'][$field] ?? null;
        }

        $data['step_groups_data'] = collect($payload['step_groups'] ?? [])->values()->map(fn (array $group, int $index): array => [
            ...$group, 'key' => isset($group['id']) ? 'group-'.$group['id'] : 'new-'.$index,
        ])->all();
        $data['step_group_removals'] = [];
        $groupKeys = array_column($data['step_groups_data'], 'key');
        $data['ingredient_groups_data'] = $payload['ingredient_groups'] ?? [];
        $data['categories'] = $payload['category_ids'] ?? [];
        $data['tags'] = self::editableTagIds($payload['tag_ids'] ?? []);
        $data['yields_data'] = $payload['yields'] ?? [];
        $data['media_data'] = $payload['media'] ?? [];

        $data['ingredients_data'] = collect($payload['ingredients'] ?? [])->map(fn (array $line): array => [
            'id' => $line['id'] ?? null,
            'group_index' => $line['group_index'] ?? null,
            'scaling_mode' => $line['scaling_mode'] ?? 'linear',
            ...self::typedFields($line),
            'ingredient_id' => $line['ingredient_id'] ?? null,
            'quantity' => $line['quantity'],
            'quantity_max' => $line['quantity_max'],
            'unit_id' => $line['unit_id'],
            'is_optional' => $line['is_optional'] ?? false,
            'preparation_note_en' => $line['translations']['en']['preparation_note'] ?? null,
            'preparation_note_el' => $line['translations']['el']['preparation_note'] ?? null,
            'quantity_text_en' => $line['translations']['en']['quantity_text'] ?? null,
            'quantity_text_el' => $line['translations']['el']['quantity_text'] ?? null,
        ])->all();

        $data['steps_data'] = collect($payload['steps'] ?? [])->map(fn (array $step): array => [
            'id' => $step['id'] ?? null,
            'step_group_key' => isset($step['step_group_index']) ? ($groupKeys[$step['step_group_index']] ?? null) : null,
            'instruction_en' => $step['translations']['en']['instruction'] ?? null,
            'instruction_el' => $step['translations']['el']['instruction'] ?? null,
            'duration_minutes' => $step['duration_minutes'] ?? null,
            'revision_media' => $step['media'] ?? [],
        ])->all();

        return $data;
    }

    /**
     * Applies form data on top of the draft's existing payload, leaving everything the form does not show intact.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function toPayload(array $payload, array $data): array
    {
        foreach (array_keys(self::LOCALES) as $locale) {
            $payload['translations'][$locale] = $data[$locale] ?? [];
        }

        foreach (['prep_minutes', 'cook_minutes', 'rest_minutes', 'base_servings', 'difficulty'] as $field) {
            $payload['recipe'][$field] = $data[$field] ?? null;
        }

        foreach (['ingredient_groups_data' => 'ingredient_groups', 'categories' => 'category_ids', 'media_data' => 'media'] as $field => $key) {
            if (array_key_exists($field, $data)) {
                $payload[$key] = $key === 'media'
                    ? collect($data[$field])->values()->map(fn (array $item, int $index): array => [...$item, 'sort_order' => $index])->all()
                    : $data[$field];
            }
        }

        if (array_key_exists('tags', $data)) {
            $payload['tag_ids'] = self::mergeTagIds($payload['tag_ids'] ?? [], $data['tags']);
        }

        if (array_key_exists('yields_data', $data)) {
            $payload['yields'] = $data['yields_data'];
        }

        if (array_key_exists('step_groups_data', $data)) {
            $payload['step_groups'] = collect($data['step_groups_data'])->values()->map(fn (array $group, int $index): array => [
                'id' => $group['id'] ?? null, 'position' => $index, 'translations' => $group['translations'] ?? [],
            ])->all();
        }
        $groupIndexes = array_flip(array_column(array_values($data['step_groups_data'] ?? []), 'key'));
        $existingSteps = collect($payload['steps'] ?? [])->keyBy(fn (array $step) => $step['id'] ?? null);

        $payload['ingredients'] = collect($data['ingredients_data'] ?? [])->values()->map(fn (array $line): array => [
            'id' => $line['id'] ?? null,
            ...self::typedFields($line),
            'ingredient_id' => $line['ingredient_id'] ?? null,
            'group_index' => $line['group_index'] ?? null,
            'quantity' => $line['quantity'] ?? null,
            'quantity_max' => $line['quantity_max'] ?? null,
            'unit_id' => $line['unit_id'] ?? null,
            'scaling_mode' => $line['scaling_mode'] ?? 'linear',
            'is_optional' => (bool) ($line['is_optional'] ?? false),
            'translations' => [
                'en' => ['preparation_note' => $line['preparation_note_en'] ?? null, 'quantity_text' => $line['quantity_text_en'] ?? null],
                'el' => ['preparation_note' => $line['preparation_note_el'] ?? null, 'quantity_text' => $line['quantity_text_el'] ?? null],
            ],
        ])->all();

        $payload['steps'] = collect($data['steps_data'] ?? [])->values()->map(fn (array $step, int $index): array => [
            'id' => $step['id'] ?? null,
            'step_number' => $index + 1,
            'step_group_index' => array_key_exists('step_group_key', $step)
                ? ($groupIndexes[$step['step_group_key'] ?? ''] ?? null)
                : ($existingSteps->get($step['id'] ?? null)['step_group_index'] ?? null),
            'duration_minutes' => $step['duration_minutes'] ?? null,
            'translations' => [
                'en' => ['instruction' => $step['instruction_en'] ?? null],
                'el' => ['instruction' => $step['instruction_el'] ?? null],
            ],
            'media' => collect($step['revision_media'] ?? $existingSteps->get($step['id'] ?? null)['media'] ?? [])->values()
                ->map(fn (array $item, int $mediaIndex): array => [...$item, 'sort_order' => $mediaIndex])->all(),
        ])->all();

        return $payload;
    }

    public static function typedFields(array $line): array
    {
        return array_replace(array_intersect_key($line, array_flip([
            'line_uuid', 'line_type', 'component_recipe_id', 'component_recipe_revision_id',
            'quantity_basis', 'shopping_mode', 'prepared_ingredient_id',
            'prepared_quantity_per_batch', 'prepared_unit_id',
        ])), ['line_type' => $line['line_type'] ?? 'ingredient']);
    }

    /** Preserve associations outside the ordinary-tag field's editable scope. */
    public static function mergeTagIds(array $existing, array $selected): array
    {
        return collect($existing)->diff(self::editableTagIds($existing))
            ->merge($selected)->map(fn ($id): int => (int) $id)->unique()->values()->all();
    }

    private static function editableTagIds(array $ids): array
    {
        return Tag::query()->whereIn('id', $ids)->where('is_active', true)->editorial()
            ->orderBy('id')->pluck('id')->all();
    }
}
