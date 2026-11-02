<?php

namespace App\Services;

use App\Models\Media;
use App\Models\MediaTranslation;
use App\Models\Recipe;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Public recipe pages show the media metadata the current published revision was reviewed with, not the shared
 * media rows, which anyone with media access may edit later. Media the revision does not list, or whose file no
 * longer matches the reviewed one, is not shown. Recipes without a published snapshot (drafts, private recipes and
 * revisions that predate snapshots) keep using the live metadata.
 */
class RecipeMediaMetadata
{
    /** @var array<int, array<int, array<string, mixed>>|null> */
    private array $snapshots = [];

    public function __construct(private RecipeRevisionSnapshotter $snapshotter) {}

    /**
     * @param  Collection<int, Media>  $media
     * @return Collection<int, Media>
     */
    public function present(Recipe $recipe, Collection $media): Collection
    {
        $snapshot = $this->snapshotFor($recipe);

        if ($snapshot === null) {
            return $media;
        }

        return $media->filter(fn (Media $item): bool => $this->apply($item, $snapshot))->values();
    }

    public function presentOne(Recipe $recipe, ?Media $media): ?Media
    {
        return $media === null ? null : $this->present($recipe, new Collection([$media]))->first();
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function snapshotFor(Recipe $recipe): ?array
    {
        if ($recipe->current_revision_id === null || ! $recipe->isPubliclyAccessible()) {
            return null;
        }

        $revisionId = (int) $recipe->current_revision_id;

        if (! array_key_exists($revisionId, $this->snapshots)) {
            $raw = DB::table('recipe_revisions')->where('id', $revisionId)->where('status', 'published')->value('media_snapshot');
            $this->snapshots[$revisionId] = $raw === null ? null : collect(json_decode($raw, true))->keyBy(fn (array $asset): int => (int) $asset['media_id'])->all();
        }

        return $this->snapshots[$revisionId];
    }

    /**
     * @param  array<int, array<string, mixed>>  $snapshot
     */
    private function apply(Media $media, array $snapshot): bool
    {
        $asset = $snapshot[$media->getKey()] ?? null;

        if ($asset === null
            || $asset['source'] !== $media->source
            || $asset['file_path'] !== $media->file_path
            || $asset['url'] !== $media->url) {
            return false;
        }

        $media->setRelation('translations', (new Collection($asset['translations'] ?? []))->map(
            fn (array $text, string $locale): MediaTranslation => (new MediaTranslation)->forceFill([
                'media_id' => $media->getKey(),
                'locale' => $locale,
                'alt_text' => $text['alt_text'] ?? '',
                'caption' => $text['caption'] ?? null,
            ])
        )->values());
        $media->forceFill(Arr::only($asset, ['credit', 'credit_url', 'width', 'height', 'mime_type']))->syncOriginal();

        return true;
    }
}
