<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeIngredientTranslation;
use App\Models\RecipeRevision;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** One fail-closed content contract for authenticated sessions and public guest snapshots. */
class CookingContentService
{
    public function __construct(
        private RecipeAccessService $access,
        private RecipeComponentResolver $components,
        private RecipeRevisionSnapshotter $snapshots,
    ) {}

    public function allows(Recipe $recipe, RecipeRevision $revision, ?User $user, bool $publicOnly): bool
    {
        if ($recipe->trashed() || $recipe->status === 'archived'
            || (int) $revision->recipe_id !== (int) $recipe->id
            || ! $this->access->canViewDirect($user, $recipe)
            || ($publicOnly && (! $recipe->isPubliclyAccessible() || ! in_array($revision->getRawOriginal('status'), ['published', 'superseded'], true)))
            || ! hash_equals($revision->content_hash, $this->snapshots->hash($revision->payload))
            || $revision->media_snapshot === null || $revision->media_snapshot_hash === null
            || ! hash_equals($revision->media_snapshot_hash, $this->snapshots->mediaSnapshotHash($revision->media_snapshot))) {
            return false;
        }
        $blocked = DB::select(<<<'SQL'
WITH RECURSIVE pinned(id) AS (
    SELECT CAST(? AS bigint)
    UNION
    SELECT dependency.component_recipe_revision_id
    FROM recipe_revision_dependencies dependency JOIN pinned ON dependency.recipe_revision_id = pinned.id
)
SELECT recipe.id FROM pinned
JOIN recipe_revisions revision ON revision.id = pinned.id
JOIN recipes recipe ON recipe.id = revision.recipe_id
WHERE recipe.status = 'archived' OR recipe.deleted_at IS NOT NULL
LIMIT 1
SQL, [$revision->id]);
        if ($blocked !== []) {
            return false;
        }
        $revision->setRelation('recipe', $recipe);
        if (! $this->components->canResolve($revision, $user)) {
            return false;
        }
        $media = Media::query()->whereIn('id', collect($this->snapshots->mediaReferences($revision->payload))->pluck('media_id'))->get()->keyBy('id');
        $assets = collect($revision->media_snapshot)->keyBy('media_id');
        foreach ($this->snapshots->mediaReferences($revision->payload) as $reference) {
            $id = $reference['media_id'];
            if (! isset($media[$id], $assets[$id]) || ! $this->snapshots->mediaIdentityMatches($assets[$id], $media[$id])) {
                return false;
            }
        }

        return true;
    }

    public function present(RecipeRevision $revision, string $servings, string $locale, bool $includeContexts = true, bool $includeMedia = true): array
    {
        $assets = $includeMedia ? ($revision->media_snapshot ?? []) : [];
        $media = $assets === [] ? collect() : Media::query()->whereIn('id', collect($assets)->pluck('media_id'))->get()->keyBy('id');
        $presenter = app(RecipeContentPresenter::class);
        $components = $presenter->components($revision, $servings);

        $contexts = [];
        if ($includeContexts) {
            $revisions = RecipeRevision::whereIn('id', array_column($components, 'revision_id'))->get()->keyBy('id');
            $presented = [];
            foreach ($components as $component) {
                $child = $revisions->get($component['revision_id']);
                $size = $component['resolved_servings'] ?? (string) $child->payload['recipe']['base_servings'];
                $cacheKey = $child->id.':'.$size;
                $presented[$cacheKey] ??= $this->present($child, $size, $locale, false, false);
                $context = $presented[$cacheKey];
                foreach ($context['components'] as &$nested) {
                    $nested['path'] = $component['path'].'/'.$nested['path'];
                }
                unset($nested);
                if ($component['quantity'] === null) {
                    $context['ingredients'] = [];
                    $context['components'] = [];
                    $context['manual_quantity'] = true;
                }
                $contexts[$component['path']] = $context;
            }
        }
        $payload = $revision->payload;
        if (! $includeMedia) {
            // A reference does not grant redistribution of child media; text contexts deliberately omit it.
            $payload['media'] = [];
            foreach ($payload['steps'] as &$step) {
                $step['media'] = [];
            }
            unset($step);
        }

        return [
            'recipe_id' => $revision->recipe_id, 'revision_id' => $revision->id,
            'content_hash' => $revision->content_hash, 'schema_version' => $revision->schema_version,
            'server_time_ms' => now()->getTimestampMs(), 'servings' => $servings,
            'content' => $payload, 'contexts' => (object) $contexts, 'step_map' => (object) app(CookingSessionProgress::class)->steps($revision->payload),
            'ingredients' => $this->ingredients($revision, $servings, $components), 'components' => $components,
            'media' => collect($assets)->map(fn (array $asset): array => [
                'id' => $asset['media_id'], 'url' => $media[$asset['media_id']]->publicUrl(),
                'type' => $asset['media_type'], 'source' => $asset['source'], 'translations' => $asset['translations'],
                'credit' => $asset['credit'], 'credit_url' => $asset['credit_url'],
            ])->values()->all(),
        ];
    }

    private function ingredients(RecipeRevision $revision, string $servings, array $components): array
    {
        $payload = $revision->payload;
        $recipe = (new Recipe)->forceFill(['id' => $revision->recipe_id, ...$payload['recipe']]);
        $ingredients = Ingredient::with(['translations', 'catalogGroup.translations', 'publicPrimaryImage.media.translations', 'publicPrimaryImage.thumbnail'])
            ->whereIn('id', collect($payload['ingredients'])->pluck('ingredient_id')->filter())->get()->keyBy('id');
        $units = Unit::with('translations')->whereIn('id', collect($payload['ingredients'])->pluck('unit_id')->filter())->get()->keyBy('id');
        $items = [];
        foreach ($payload['ingredients'] as $index => $row) {
            $line = (new RecipeIngredient)->forceFill([...array_diff_key($row, ['translations' => true]), 'line_type' => $row['line_type'] ?? 'ingredient']);
            $line->setRelation('recipe', $recipe);
            $line->setRelation('translations', collect($row['translations'] ?? [])->map(fn (array $text, string $locale) => (new RecipeIngredientTranslation)->forceFill(['locale' => $locale, ...$text]))->values());
            $line->setRelation('ingredient', $ingredients->get($row['ingredient_id'] ?? null));
            $line->setRelation('unit', $units->get($row['unit_id'] ?? null));
            $group = isset($row['group_index']) ? ($payload['ingredient_groups'][$row['group_index']]['names'][app()->getLocale()] ?? null) : null;
            $items[] = ['key' => 'i'.$index, 'group' => $group,
                ...app(RecipeContentPresenter::class)->cookingIngredient($recipe, $line, $servings, $components)];
        }

        return $items;
    }
}
