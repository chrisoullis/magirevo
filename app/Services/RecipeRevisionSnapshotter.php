<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Turns a recipe's live content into a canonical payload and a content hash.
 *
 * Database row IDs are carried for applying a payload but excluded from the hash, so a payload that adds new
 * lines hashes the same before and after it is applied. Everything a reader can see is hashed.
 */
class RecipeRevisionSnapshotter
{
    public const SCHEMA_VERSION = 1;

    private const RECIPE_FIELDS = ['prep_minutes', 'cook_minutes', 'rest_minutes', 'base_servings', 'difficulty'];

    private const TRANSLATED_FIELDS = ['title', 'slug', 'summary', 'introduction', 'seo_title', 'seo_description'];

    /**
     * @return array<string, mixed>
     */
    public function serialize(int $recipeId): array
    {
        $recipe = DB::table('recipes')->where('id', $recipeId)->first(self::RECIPE_FIELDS);

        $groups = DB::table('ingredient_groups')->where('recipe_id', $recipeId)->orderBy('sort_order')->orderBy('id')->get(['id', 'sort_order']);
        $groupNames = DB::table('ingredient_group_translations')->whereIn('ingredient_group_id', $groups->pluck('id'))->orderBy('locale')->get()->groupBy('ingredient_group_id');
        $groupIndex = $groups->pluck('id')->flip();

        $lines = DB::table('recipe_ingredients')->where('recipe_id', $recipeId)->orderBy('sort_order')->orderBy('id')->get();
        $lineTexts = DB::table('recipe_ingredient_translations')->whereIn('recipe_ingredient_id', $lines->pluck('id'))->orderBy('locale')->get()->groupBy('recipe_ingredient_id');

        $steps = DB::table('recipe_steps')->where('recipe_id', $recipeId)->orderBy('step_number')->orderBy('id')->get();
        $stepTexts = DB::table('recipe_step_translations')->whereIn('recipe_step_id', $steps->pluck('id'))->orderBy('locale')->get()->groupBy('recipe_step_id');
        $stepMedia = DB::table('recipe_step_media')->whereIn('recipe_step_id', $steps->pluck('id'))->orderBy('sort_order')->orderBy('media_id')->get()->groupBy('recipe_step_id');

        return [
            'recipe' => [
                'prep_minutes' => (int) $recipe->prep_minutes,
                'cook_minutes' => (int) $recipe->cook_minutes,
                'rest_minutes' => (int) $recipe->rest_minutes,
                'base_servings' => $this->decimal($recipe->base_servings),
                'difficulty' => $recipe->difficulty,
            ],
            'translations' => DB::table('recipe_translations')->where('recipe_id', $recipeId)->orderBy('locale')->get()
                ->mapWithKeys(fn (object $row): array => [$row->locale => collect(self::TRANSLATED_FIELDS)->mapWithKeys(fn (string $field): array => [$field => $row->{$field}])->all()])
                ->all(),
            'category_ids' => DB::table('recipe_category')->where('recipe_id', $recipeId)->orderBy('category_id')->pluck('category_id')->map(fn ($id): int => (int) $id)->all(),
            'tag_ids' => DB::table('recipe_tag')->where('recipe_id', $recipeId)->orderBy('tag_id')->pluck('tag_id')->map(fn ($id): int => (int) $id)->all(),
            'media' => DB::table('recipe_media')->where('recipe_id', $recipeId)->orderBy('sort_order')->orderBy('media_id')->get()
                ->map(fn (object $row): array => ['media_id' => (int) $row->media_id, 'role' => $row->role, 'sort_order' => (int) $row->sort_order])->all(),
            'ingredient_groups' => $groups->map(fn (object $group): array => [
                'id' => (int) $group->id,
                'sort_order' => (int) $group->sort_order,
                'names' => ($groupNames[$group->id] ?? collect())->mapWithKeys(fn (object $row): array => [$row->locale => $row->name])->all(),
            ])->all(),
            'ingredients' => $lines->map(fn (object $line): array => [
                'id' => (int) $line->id,
                'ingredient_id' => (int) $line->ingredient_id,
                'group_index' => $line->ingredient_group_id !== null ? ($groupIndex[$line->ingredient_group_id] ?? null) : null,
                'quantity' => $this->decimal($line->quantity),
                'quantity_max' => $this->decimal($line->quantity_max),
                'unit_id' => $line->unit_id !== null ? (int) $line->unit_id : null,
                'scaling_mode' => $line->scaling_mode,
                'is_optional' => (bool) $line->is_optional,
                'sort_order' => (int) $line->sort_order,
                'translations' => ($lineTexts[$line->id] ?? collect())->mapWithKeys(fn (object $row): array => [$row->locale => [
                    'preparation_note' => $row->preparation_note,
                    'quantity_text' => $row->quantity_text,
                ]])->all(),
            ])->all(),
            'steps' => $steps->map(fn (object $step): array => [
                'id' => (int) $step->id,
                'step_number' => (int) $step->step_number,
                'duration_minutes' => $step->duration_minutes !== null ? (int) $step->duration_minutes : null,
                'translations' => ($stepTexts[$step->id] ?? collect())->mapWithKeys(fn (object $row): array => [$row->locale => ['instruction' => $row->instruction]])->all(),
                'media' => ($stepMedia[$step->id] ?? collect())->map(fn (object $row): array => [
                    'media_id' => (int) $row->media_id,
                    'kind' => $row->kind,
                    'sort_order' => (int) $row->sort_order,
                ])->all(),
            ])->all(),
        ];
    }

    /**
     * Canonical JSON of everything a reader can see: keys sorted, database row IDs removed.
     *
     * @param  array<string, mixed>  $payload
     */
    public function canonicalJson(array $payload): string
    {
        foreach (['ingredient_groups', 'ingredients', 'steps'] as $collection) {
            $payload[$collection] = array_map(function (array $row): array {
                unset($row['id']);

                return $row;
            }, array_values($payload[$collection] ?? []));
        }

        return json_encode($this->sortKeys($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function hash(array $payload): string
    {
        return hash('sha256', $this->canonicalJson($payload));
    }

    /**
     * Every media item a payload refers to, with the role it plays.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array{media_id: int, usage: string}>
     */
    public function mediaReferences(array $payload): array
    {
        $references = [];

        foreach ($payload['media'] ?? [] as $item) {
            $references[] = ['media_id' => (int) $item['media_id'], 'usage' => (string) $item['role']];
        }

        foreach ($payload['steps'] ?? [] as $step) {
            foreach ($step['media'] ?? [] as $item) {
                $references[] = ['media_id' => (int) $item['media_id'], 'usage' => 'step_'.$item['kind']];
            }
        }

        return array_values(collect($references)->unique(fn (array $item): string => $item['media_id'].':'.$item['usage'])->all());
    }

    /**
     * Immutable media metadata for every media item the payload shows: file identity, credit, dimensions and the
     * English and Greek alt text and captions. Entries already held in $preferred win, so a revision keeps the
     * metadata it was reviewed with even when the shared media rows are edited later.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<array<string, mixed>>|null  $preferred
     * @return list<array<string, mixed>>
     */
    public function snapshotMedia(array $payload, ?array $preferred = null): array
    {
        $ids = collect($this->mediaReferences($payload))->pluck('media_id')->unique()->sort()->values();
        $held = collect($preferred ?? [])->keyBy(fn (array $asset): int => (int) $asset['media_id']);
        $missing = $ids->reject(fn (int $id): bool => $held->has($id));

        $live = collect();

        if ($missing->isNotEmpty()) {
            $texts = DB::table('media_translations')->whereIn('media_id', $missing)->orderBy('locale')->get()->groupBy('media_id');
            $live = DB::table('media')->whereIn('id', $missing)->get()->keyBy('id')->map(fn (object $row): array => [
                'media_id' => (int) $row->id,
                'media_type' => $row->media_type,
                'source' => $row->source,
                'file_path' => $row->file_path,
                'url' => $row->url,
                'mime_type' => $row->mime_type,
                'file_size' => $row->file_size !== null ? (int) $row->file_size : null,
                'width' => $row->width !== null ? (int) $row->width : null,
                'height' => $row->height !== null ? (int) $row->height : null,
                'credit' => $row->credit,
                'credit_url' => $row->credit_url,
                'translations' => ($texts[$row->id] ?? collect())->mapWithKeys(fn (object $text): array => [$text->locale => ['alt_text' => $text->alt_text, 'caption' => $text->caption]])->all(),
            ]);
        }

        return $ids->map(fn (int $id): ?array => $held->get($id) ?? $live->get($id))->filter()->values()->all();
    }

    /**
     * @param  list<array<string, mixed>>  $snapshot
     */
    public function mediaSnapshotHash(array $snapshot): string
    {
        return hash('sha256', json_encode($this->sortKeys(array_values($snapshot)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * Whether the live media row still names the same file or remote address the snapshot recorded.
     *
     * @param  array<string, mixed>  $asset
     */
    public function mediaIdentityMatches(array $asset, object $mediaRow): bool
    {
        return ($asset['source'] ?? null) === $mediaRow->source
            && ($asset['file_path'] ?? null) === $mediaRow->file_path
            && ($asset['url'] ?? null) === $mediaRow->url;
    }

    /**
     * Puts an edited payload into the exact shape serialize() produces for applied content: lists renumbered, blanks
     * nulled, decimals trimmed, both locales present. A payload that is normalised hashes the same once applied.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function normalize(array $payload): array
    {
        $locales = (array) config('translatable.locales');
        $text = fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null;
        $id = fn (mixed $value): ?int => filled($value) && is_numeric($value) ? (int) $value : null;
        $ids = fn (mixed $list): array => collect((array) $list)->filter(fn ($v): bool => is_numeric($v))->map(fn ($v): int => (int) $v)->unique()->sort()->values()->all();

        $recipe = (array) ($payload['recipe'] ?? []);
        $translations = [];

        foreach ((array) ($payload['translations'] ?? []) as $locale => $row) {
            $translations[$locale] = collect(self::TRANSLATED_FIELDS)->mapWithKeys(fn (string $field): array => [$field => $text(((array) $row)[$field] ?? null)])->all();
        }

        $media = collect((array) ($payload['media'] ?? []))
            ->filter(fn ($item): bool => is_numeric($item['media_id'] ?? null))
            ->unique(fn ($item): int => (int) $item['media_id'])
            ->sortBy([fn ($a, $b): int => (int) ($a['sort_order'] ?? 0) <=> (int) ($b['sort_order'] ?? 0), fn ($a, $b): int => (int) $a['media_id'] <=> (int) $b['media_id']])
            ->values()
            ->map(fn (array $item, int $index): array => ['media_id' => (int) $item['media_id'], 'role' => $item['role'] ?? 'gallery', 'sort_order' => $index])
            ->all();

        $groups = collect((array) ($payload['ingredient_groups'] ?? []))->values()->map(fn (array $group, int $index): array => [
            'id' => $id($group['id'] ?? null),
            'sort_order' => $index,
            'names' => collect($locales)->mapWithKeys(fn (string $locale): array => [$locale => $text(((array) ($group['names'] ?? []))[$locale] ?? null)])->filter()->all(),
        ])->all();

        $ingredients = collect((array) ($payload['ingredients'] ?? []))->values()->map(fn (array $line, int $index): array => [
            'id' => $id($line['id'] ?? null),
            'ingredient_id' => (int) ($line['ingredient_id'] ?? 0),
            'group_index' => isset($line['group_index'], $groups[$line['group_index']]) ? (int) $line['group_index'] : null,
            'quantity' => $this->decimal(filled($line['quantity'] ?? null) ? $line['quantity'] : null),
            'quantity_max' => $this->decimal(filled($line['quantity_max'] ?? null) ? $line['quantity_max'] : null),
            'unit_id' => $id($line['unit_id'] ?? null),
            'scaling_mode' => $line['scaling_mode'] ?? 'linear',
            'is_optional' => (bool) ($line['is_optional'] ?? false),
            'sort_order' => $index,
            'translations' => collect($locales)->mapWithKeys(fn (string $locale): array => [$locale => [
                'preparation_note' => $text(((array) ($line['translations'] ?? []))[$locale]['preparation_note'] ?? null),
                'quantity_text' => $text(((array) ($line['translations'] ?? []))[$locale]['quantity_text'] ?? null),
            ]])->all(),
        ])->all();

        $steps = collect((array) ($payload['steps'] ?? []))->values()->map(fn (array $step, int $index): array => [
            'id' => $id($step['id'] ?? null),
            'step_number' => $index + 1,
            'duration_minutes' => $id($step['duration_minutes'] ?? null),
            'translations' => collect($locales)->mapWithKeys(fn (string $locale): array => [$locale => ['instruction' => (string) (((array) ($step['translations'] ?? []))[$locale]['instruction'] ?? '')]])->all(),
            'media' => collect((array) ($step['media'] ?? []))
                ->filter(fn ($item): bool => is_numeric($item['media_id'] ?? null))
                ->unique(fn ($item): int => (int) $item['media_id'])
                ->sortBy([fn ($a, $b): int => (int) ($a['sort_order'] ?? 0) <=> (int) ($b['sort_order'] ?? 0), fn ($a, $b): int => (int) $a['media_id'] <=> (int) $b['media_id']])
                ->values()
                ->map(fn (array $item, int $mediaIndex): array => ['media_id' => (int) $item['media_id'], 'kind' => $item['kind'] ?? 'image', 'sort_order' => $mediaIndex])
                ->all(),
        ])->all();

        return [
            'recipe' => [
                'prep_minutes' => (int) ($recipe['prep_minutes'] ?? 0),
                'cook_minutes' => (int) ($recipe['cook_minutes'] ?? 0),
                'rest_minutes' => (int) ($recipe['rest_minutes'] ?? 0),
                'base_servings' => $this->decimal($recipe['base_servings'] ?? null),
                'difficulty' => $text($recipe['difficulty'] ?? null),
            ],
            'translations' => $translations,
            'category_ids' => $ids($payload['category_ids'] ?? []),
            'tag_ids' => $ids($payload['tag_ids'] ?? []),
            'media' => $media,
            'ingredient_groups' => $groups,
            'ingredients' => $ingredients,
            'steps' => $steps,
        ];
    }

    /**
     * Drops row IDs that are not rows of this recipe's live content, so a copied payload cannot point at another
     * recipe's lines or at lines that no longer exist. Dropped IDs become new rows when applied.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function rebaseIds(array $payload, int $recipeId): array
    {
        $live = [
            'ingredient_groups' => DB::table('ingredient_groups')->where('recipe_id', $recipeId)->pluck('id')->map(fn ($v): int => (int) $v)->all(),
            'ingredients' => DB::table('recipe_ingredients')->where('recipe_id', $recipeId)->pluck('id')->map(fn ($v): int => (int) $v)->all(),
            'steps' => DB::table('recipe_steps')->where('recipe_id', $recipeId)->pluck('id')->map(fn ($v): int => (int) $v)->all(),
        ];

        foreach ($live as $collection => $knownIds) {
            $seen = [];
            $payload[$collection] = array_map(function (array $row) use ($knownIds, &$seen): array {
                $id = isset($row['id']) ? (int) $row['id'] : null;
                $row['id'] = $id !== null && in_array($id, $knownIds, true) && ! in_array($id, $seen, true) ? $id : null;

                if ($row['id'] !== null) {
                    $seen[] = $row['id'];
                }

                return $row;
            }, array_values($payload[$collection] ?? []));
        }

        return $payload;
    }

    public function decimal(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = (string) $value;

        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }

    private function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(fn (mixed $item): mixed => $this->sortKeys($item), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
