<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/**
 * Turns a recipe's live content into a canonical payload and a content hash.
 *
 * Database row IDs are carried for applying a payload but excluded from the hash, so a payload that adds new
 * lines hashes the same before and after it is applied. Everything a reader can see is hashed.
 */
class RecipeRevisionSnapshotter
{
    public const SCHEMA_VERSION = 3;

    private const RECIPE_FIELDS = ['prep_minutes', 'cook_minutes', 'rest_minutes', 'base_servings', 'difficulty'];

    private const TRANSLATED_FIELDS = ['title', 'slug', 'summary', 'introduction', 'seo_title', 'seo_description'];

    /**
     * @return array<string, mixed>
     */
    public function serialize(int $recipeId, array $units = []): array
    {
        $recipe = DB::table('recipes')->where('id', $recipeId)->first(self::RECIPE_FIELDS);

        $groups = DB::table('ingredient_groups')->where('recipe_id', $recipeId)->orderBy('sort_order')->orderBy('id')->get(['id', 'sort_order']);
        $groupNames = DB::table('ingredient_group_translations')->whereIn('ingredient_group_id', $groups->pluck('id'))->orderBy('locale')->get()->groupBy('ingredient_group_id');
        $groupIndex = $groups->pluck('id')->flip();

        $lines = DB::table('recipe_ingredients')->where('recipe_id', $recipeId)->orderBy('sort_order')->orderBy('id')->get();
        $lineTexts = DB::table('recipe_ingredient_translations')->whereIn('recipe_ingredient_id', $lines->pluck('id'))->orderBy('locale')->get()->groupBy('recipe_ingredient_id');

        $hasStepGroups = Schema::hasTable('recipe_step_groups');
        $stepGroups = $hasStepGroups ? DB::table('recipe_step_groups')->where('recipe_id', $recipeId)->orderBy('position')->orderBy('id')->get() : collect();
        $stepGroupTexts = $hasStepGroups ? DB::table('recipe_step_group_translations')->whereIn('recipe_step_group_id', $stepGroups->pluck('id'))->orderBy('locale')->get()->groupBy('recipe_step_group_id') : collect();
        $stepGroupIndex = $stepGroups->pluck('id')->flip();
        $steps = DB::table('recipe_steps')->where('recipe_id', $recipeId)->orderBy('step_number')->orderBy('id')->get();
        $stepTexts = DB::table('recipe_step_translations')->whereIn('recipe_step_id', $steps->pluck('id'))->orderBy('locale')->get()->groupBy('recipe_step_id');
        $stepMedia = DB::table('recipe_step_media')->whereIn('recipe_step_id', $steps->pluck('id'))->orderBy('sort_order')->orderBy('media_id')->get()->groupBy('recipe_step_id');

        $payload = [
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
                'ingredient_id' => $line->ingredient_id !== null ? (int) $line->ingredient_id : null,
                ...(isset($line->line_uuid) ? $this->componentFields((array) $line) : []),
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
            'yields' => ! Schema::hasTable('recipe_yields') ? [] : DB::table('recipe_yields')->where('recipe_id', $recipeId)->orderBy('basis')->get()->map(fn (object $yield): array => $this->yieldFields((array) $yield))->all(),
            'step_groups' => $stepGroups->values()->map(fn (object $group, int $index): array => [
                'id' => (int) $group->id, 'position' => $index,
                'translations' => ($stepGroupTexts[$group->id] ?? collect())->mapWithKeys(fn (object $row): array => [
                    $row->locale => ['title' => $row->title, 'description' => $row->description],
                ])->all(),
            ])->all(),
            'steps' => $steps->map(fn (object $step): array => [
                'id' => (int) $step->id,
                'step_number' => (int) $step->step_number,
                'step_group_index' => isset($step->recipe_step_group_id) ? $stepGroupIndex[$step->recipe_step_group_id] : null,
                'duration_minutes' => $step->duration_minutes !== null ? (int) $step->duration_minutes : null,
                'translations' => ($stepTexts[$step->id] ?? collect())->mapWithKeys(fn (object $row): array => [$row->locale => ['instruction' => $row->instruction]])->all(),
                'media' => ($stepMedia[$step->id] ?? collect())->map(fn (object $row): array => [
                    'media_id' => (int) $row->media_id,
                    'kind' => $row->kind,
                    'sort_order' => (int) $row->sort_order,
                ])->all(),
            ])->all(),
        ];

        // Earlier baseline migrations invoke this service before the v2 tables exist.
        if (! Schema::hasTable('recipe_yields')) {
            unset($payload['yields']);

            return $payload;
        }

        // A fork inherits conversion definitions from its immutable provenance, not mutable unit rows.
        if (Schema::hasTable('recipe_derivations')) {
            $source = DB::table('recipe_derivations')->join('recipe_revisions', 'recipe_revisions.id', '=', 'recipe_derivations.source_recipe_revision_id')
                ->where('recipe_derivations.recipe_id', $recipeId)->value('recipe_revisions.payload');
            if ($source !== null) {
                $source = is_string($source) ? json_decode($source, true, flags: JSON_THROW_ON_ERROR) : (array) $source;
                $units += $source['units'] ?? [];
            }
        }

        return [...$payload, 'units' => $this->snapshotUnits($payload, $units)];
    }

    /**
     * Canonical JSON of everything a reader can see: keys sorted, database row IDs removed.
     *
     * @param  array<string, mixed>  $payload
     */
    public function canonicalJson(array $payload): string
    {
        foreach (['ingredient_groups', 'ingredients', 'steps', ...(array_key_exists('step_groups', $payload) ? ['step_groups'] : [])] as $collection) {
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
            'ingredient_id' => ($line['line_type'] ?? 'ingredient') === 'recipe' ? null : (int) ($line['ingredient_id'] ?? 0),
            ...$this->componentFields($line),
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

        $stepGroups = collect($payload['step_groups'] ?? [])->values()->map(fn (array $group, int $index): array => [
            'id' => $id($group['id'] ?? null), 'position' => $index,
            'translations' => collect($locales)->mapWithKeys(fn (string $locale): array => [$locale => [
                'title' => (string) ($text($group['translations'][$locale]['title'] ?? null) ?? ''),
                'description' => $text($group['translations'][$locale]['description'] ?? null),
            ]])->all(),
        ])->all();
        $steps = collect((array) ($payload['steps'] ?? []))->values()->map(fn (array $step, int $index): array => [
            'id' => $id($step['id'] ?? null),
            'step_number' => $index + 1,
            'step_group_index' => filter_var($step['step_group_index'] ?? null, FILTER_VALIDATE_INT) !== false ? (int) $step['step_group_index'] : ($step['step_group_index'] ?? null),
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

        $normalized = [
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
            'step_groups' => $stepGroups,
            'yields' => collect($payload['yields'] ?? [])->sortBy('basis')->values()->map(fn (array $yield): array => $this->yieldFields($yield))->all(),
        ];

        return [...$normalized, 'units' => $this->snapshotUnits($normalized, $payload['units'] ?? [])];
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

        if (array_key_exists('step_groups', $payload)) {
            $live['step_groups'] = Schema::hasTable('recipe_step_groups')
                ? DB::table('recipe_step_groups')->where('recipe_id', $recipeId)->pluck('id')->map(fn ($v): int => (int) $v)->all() : [];
        }
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

        $lines = DB::table('recipe_ingredients')->where('recipe_id', $recipeId)->get()->keyBy('id');
        foreach ($payload['ingredients'] as &$line) {
            if (! isset($line['line_uuid']) && isset($line['id'])) {
                $line['line_uuid'] = $lines->get($line['id'])?->line_uuid;
            }
            if (isset($line['line_uuid']) && DB::table('recipe_ingredients')->where('line_uuid', $line['line_uuid'])->where('recipe_id', '!=', $recipeId)->exists()) {
                $line['line_uuid'] = (string) Str::uuid();
            }
        }
        unset($line);

        return $payload;
    }

    /** Pin conversion definitions; supplied historical definitions always win. */
    private function snapshotUnits(array $payload, array $preferred = []): array
    {
        $ids = collect($payload['ingredients'] ?? [])->flatMap(fn (array $line): array => [$line['unit_id'] ?? null, $line['prepared_unit_id'] ?? null])
            ->merge(collect($payload['yields'] ?? [])->pluck('unit_id'))->filter()->map(fn ($id): int => (int) $id)->unique()->sort()->values();
        $missing = $ids->reject(fn (int $id): bool => array_key_exists($id, $preferred));
        $live = $missing->isEmpty() ? collect() : DB::table('units')->whereIn('id', $missing)->get()->keyBy('id');
        $units = [];
        foreach ($ids as $id) {
            if (array_key_exists($id, $preferred)) {
                $units[$id] = $preferred[$id];

                continue;
            }
            $unit = $live->get($id);
            if ($unit !== null) {
                $units[$id] = [
                    'code' => $unit->code,
                    'unit_type' => $unit->unit_type,
                    'base_conversion' => $this->decimal($unit->base_conversion),
                    'allow_fraction' => (bool) $unit->allow_fraction,
                    'is_scalable' => (bool) $unit->is_scalable,
                ];
            }
        }

        return $units;
    }

    /** Typed v2 fields. Legacy line IDs give upgrades a repeatable identity. */
    private function componentFields(array $line): array
    {
        $uuid = $line['line_uuid'] ?? (isset($line['id'])
            ? (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'recipe-line:'.$line['id'])
            : (string) Str::uuid());

        return [
            'line_uuid' => $uuid,
            'line_type' => $line['line_type'] ?? 'ingredient',
            'component_recipe_id' => isset($line['component_recipe_id']) ? (int) $line['component_recipe_id'] : null,
            'component_recipe_revision_id' => isset($line['component_recipe_revision_id']) ? (int) $line['component_recipe_revision_id'] : null,
            'quantity_basis' => $line['quantity_basis'] ?? null,
            'shopping_mode' => $line['shopping_mode'] ?? null,
            'prepared_ingredient_id' => isset($line['prepared_ingredient_id']) ? (int) $line['prepared_ingredient_id'] : null,
            'prepared_quantity_per_batch' => $this->decimal($line['prepared_quantity_per_batch'] ?? null),
            'prepared_unit_id' => isset($line['prepared_unit_id']) ? (int) $line['prepared_unit_id'] : null,
        ];
    }

    private function yieldFields(array $yield): array
    {
        return [
            'basis' => $yield['basis'],
            'quantity' => $this->decimal($yield['quantity']),
            'unit_id' => (int) $yield['unit_id'],
            'evidence' => is_string($yield['evidence']) ? json_decode($yield['evidence'], true, flags: JSON_THROW_ON_ERROR) : $yield['evidence'],
            'verified_by' => (int) $yield['verified_by'],
            'verified_at' => Carbon::parse($yield['verified_at'])->utc()->format('Y-m-d H:i:s'),
        ];
    }

    /** Project live v2 content when checking an immutable v1 revision. */
    public function forSchema(array $payload, int $version): array
    {
        if ($version < 3) {
            unset($payload['step_groups']);
            foreach ($payload['steps'] ?? [] as $index => $step) {
                unset($payload['steps'][$index]['step_group_index']);
            }
        }
        if ($version < 2) {
            unset($payload['yields'], $payload['units']);
            foreach ($payload['ingredients'] as &$line) {
                foreach (['line_uuid', 'line_type', 'component_recipe_id', 'component_recipe_revision_id', 'quantity_basis', 'shopping_mode', 'prepared_ingredient_id', 'prepared_quantity_per_batch', 'prepared_unit_id'] as $field) {
                    unset($line[$field]);
                }
            }
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
