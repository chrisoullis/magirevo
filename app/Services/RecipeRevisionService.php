<?php

namespace App\Services;

use App\Enums\RecipeRevisionStatus;
use App\Enums\RecipeVisibility;
use App\Models\Recipe;
use App\Models\RecipeRevision;
use App\Models\RecipeRevisionAudit;
use App\Models\User;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class RecipeRevisionService
{
    public function __construct(
        private RecipeRevisionSnapshotter $snapshotter,
        private RecipeRevisionApplier $applier,
        private RecipeRevisionValidator $validator,
        private RecipeContentGuard $guard,
    ) {}

    /**
     * Recipes that are public and not deleted are the ones whose edits must go through revisions.
     */
    public function isLive(Recipe $recipe): bool
    {
        return $recipe->status === 'published' && $recipe->visibility === RecipeVisibility::Public && ! $recipe->trashed();
    }

    public function openRevision(Recipe $recipe): ?RecipeRevision
    {
        return RecipeRevision::query()->where('recipe_id', $recipe->getKey())->whereIn('status', ['draft', 'in_review'])->first();
    }

    /**
     * Anyone who created, edited or submitted a revision is involved in it and cannot approve it.
     */
    public function isInvolved(RecipeRevision $revision, User $user): bool
    {
        $id = (int) $user->getKey();

        return (int) $revision->created_by === $id
            || (int) $revision->submitted_by === $id
            || RecipeRevisionAudit::query()->where('recipe_revision_id', $revision->getKey())->where('actor_id', $id)->whereIn('action', ['created', 'edited', 'submitted', 'restored'])->exists();
    }

    /**
     * Starts the one open draft for a public recipe, from its current content or from a rejected revision. The
     * published content is never touched.
     */
    public function createDraft(User $actor, Recipe $recipe, ?RecipeRevision $from = null): RecipeRevision
    {
        if ($from !== null) {
            Gate::forUser($actor)->authorize('reviseRejected', $from);
            abort_unless($from->recipe_id === $recipe->getKey(), 404);
        } else {
            Gate::forUser($actor)->authorize('createDraft', [RecipeRevision::class, $recipe]);
        }

        try {
            return DB::transaction(function () use ($actor, $recipe, $from): RecipeRevision {
                $this->lockRecipe($recipe->getKey());

                if ($this->openRevision($recipe) !== null) {
                    throw ValidationException::withMessages(['revision' => 'This recipe already has a revision in progress.']);
                }

                // Direct edits made while the guard was only monitoring are captured before the draft is based on them.
                $current = $this->syncPublishedFromLive($recipe->getKey(), $actor->getKey(), 'baseline_resynced');

                $payload = $from !== null
                    ? $this->snapshotter->rebaseIds($from->payload, $recipe->getKey())
                    : $current->payload;
                $payload = $this->snapshotter->normalize($payload);

                $revision = RecipeRevision::query()->create([
                    'recipe_id' => $recipe->getKey(),
                    'revision_number' => $this->nextNumber($recipe->getKey()),
                    'status' => RecipeRevisionStatus::Draft->value,
                    'payload' => $payload,
                    ...$this->mediaColumns($payload, ($from ?? $current)->media_snapshot),
                    'schema_version' => RecipeRevisionSnapshotter::SCHEMA_VERSION,
                    'content_hash' => $this->snapshotter->hash($payload),
                    'base_revision_id' => $current->getKey(),
                    'created_by' => $actor->getKey(),
                ]);

                $this->audit($revision, $actor->getKey(), 'created', null, RecipeRevisionStatus::Draft, null, $from !== null ? ['continued_from_revision_id' => $from->getKey()] : null);

                return $revision;
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() !== '23505') {
                throw $exception;
            }

            throw ValidationException::withMessages(['revision' => 'This recipe already has a revision in progress.']);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function saveDraft(User $actor, RecipeRevision $revision, array $payload): RecipeRevision
    {
        Gate::forUser($actor)->authorize('edit', $revision);

        return DB::transaction(function () use ($actor, $revision, $payload): RecipeRevision {
            $this->lockRecipe($revision->recipe_id);
            $revision = $this->lockRevision($revision);
            $this->assertStatus($revision, RecipeRevisionStatus::Draft);

            $normalized = $this->snapshotter->normalize($payload);
            $hash = $this->snapshotter->hash($normalized);
            $media = $this->mediaColumns($normalized, $revision->media_snapshot);

            if ($hash === $revision->content_hash && $media['media_snapshot_hash'] === $revision->media_snapshot_hash) {
                return $revision;
            }

            $revision->update(['payload' => $normalized, 'content_hash' => $hash, ...$media]);
            $this->audit($revision, $actor->getKey(), 'edited', RecipeRevisionStatus::Draft, RecipeRevisionStatus::Draft, null);

            return $revision;
        });
    }

    public function discard(User $actor, RecipeRevision $revision): void
    {
        Gate::forUser($actor)->authorize('discard', $revision);

        DB::transaction(function () use ($actor, $revision): void {
            $this->lockRecipe($revision->recipe_id);
            $revision = $this->lockRevision($revision);
            $this->assertStatus($revision, RecipeRevisionStatus::Draft);

            $this->audit($revision, $actor->getKey(), 'discarded', RecipeRevisionStatus::Draft, null, null);
            $revision->delete();
        });
    }

    /**
     * Validates and freezes the draft for editorial review. From here the payload cannot change.
     */
    public function submit(User $actor, RecipeRevision $revision): RecipeRevision
    {
        Gate::forUser($actor)->authorize('submit', $revision);

        return DB::transaction(function () use ($actor, $revision): RecipeRevision {
            $recipeRow = $this->lockRecipe($revision->recipe_id);
            $revision = $this->lockRevision($revision);
            $this->assertStatus($revision, RecipeRevisionStatus::Draft);
            $this->assertBasedOnCurrent($revision, $recipeRow);

            $payload = $this->snapshotter->normalize($revision->payload);

            if ($this->snapshotter->hash($payload) === $this->baseHash($revision)) {
                throw ValidationException::withMessages(['revision' => 'There are no changes to submit.']);
            }

            $this->validator->assertSubmittable($payload, $revision->recipe_id);

            $revision->update([
                'payload' => $payload,
                ...$this->mediaColumns($payload, $revision->media_snapshot),
                'content_hash' => $this->snapshotter->hash($payload),
                'status' => RecipeRevisionStatus::InReview->value,
                'submitted_by' => $actor->getKey(),
                'submitted_at' => now(),
            ]);

            $this->retainMedia($revision);
            $this->audit($revision, $actor->getKey(), 'submitted', RecipeRevisionStatus::Draft, RecipeRevisionStatus::InReview, null);

            return $revision;
        });
    }

    /**
     * Approval publishes: the approved payload is applied to the live recipe in one transaction and rolled back if the
     * resulting live content does not hash to exactly what was reviewed.
     */
    public function approve(User $reviewer, RecipeRevision $revision, ?string $notes = null): RecipeRevision
    {
        Gate::forUser($reviewer)->authorize('approve', $revision);

        return DB::transaction(function () use ($reviewer, $revision, $notes): RecipeRevision {
            $recipeRow = $this->lockRecipe($revision->recipe_id);
            $revision = $this->lockRevision($revision);
            $this->assertStatus($revision, RecipeRevisionStatus::InReview);
            $this->assertLive($revision->recipe);
            $this->assertBasedOnCurrent($revision, $recipeRow);
            $this->validator->assertSubmittable($this->snapshotter->normalize($revision->payload), $revision->recipe_id);

            return $this->publishPayload(
                $revision->recipe_id,
                $reviewer,
                'approved',
                $notes,
                function () use ($revision, $reviewer, $notes): RecipeRevision {
                    $revision->update([
                        'status' => RecipeRevisionStatus::Published->value,
                        'reviewed_by' => $reviewer->getKey(),
                        'reviewed_at' => now(),
                        'review_notes' => $notes,
                        'published_by' => $reviewer->getKey(),
                        'published_at' => now(),
                    ]);

                    return $revision;
                },
                RecipeRevisionStatus::InReview,
            );
        });
    }

    public function reject(User $reviewer, RecipeRevision $revision, string $reason): RecipeRevision
    {
        Gate::forUser($reviewer)->authorize('reject', $revision);

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages(['rejection_reason' => 'Give a rejection reason of up to 1000 characters.']);
        }

        return DB::transaction(function () use ($reviewer, $revision, $reason): RecipeRevision {
            $this->lockRecipe($revision->recipe_id);
            $revision = $this->lockRevision($revision);
            $this->assertStatus($revision, RecipeRevisionStatus::InReview);

            $revision->update([
                'status' => RecipeRevisionStatus::Rejected->value,
                'reviewed_by' => $reviewer->getKey(),
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ]);

            $this->audit($revision, $reviewer->getKey(), 'rejected', RecipeRevisionStatus::InReview, RecipeRevisionStatus::Rejected, $reason);

            return $revision;
        });
    }

    /**
     * Restores an earlier revision's content as a brand-new published revision. History is never modified.
     */
    public function rollback(User $actor, RecipeRevision $target, string $reason): RecipeRevision
    {
        Gate::forUser($actor)->authorize('rollback', $target);

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages(['reason' => 'Give a reason of up to 1000 characters.']);
        }

        return DB::transaction(function () use ($actor, $target, $reason): RecipeRevision {
            $recipeRow = $this->lockRecipe($target->recipe_id);
            $this->assertLive($target->recipe);

            $current = RecipeRevision::query()->where('recipe_id', $target->recipe_id)->where('status', RecipeRevisionStatus::Published->value)->first();

            if ($current === null || (int) $recipeRow->current_revision_id !== $current->getKey()) {
                throw ValidationException::withMessages(['revision' => 'The recipe has no consistent published revision to roll back from.']);
            }

            if ($current->is($target) || $current->content_hash === $target->content_hash) {
                throw ValidationException::withMessages(['revision' => 'That revision is already the published content.']);
            }

            $payload = $this->snapshotter->normalize($this->snapshotter->rebaseIds($target->payload, $target->recipe_id));
            $this->validator->assertSubmittable($payload, $target->recipe_id);

            return $this->publishPayload(
                $target->recipe_id,
                $actor,
                'rolled_back',
                $reason,
                fn (): RecipeRevision => RecipeRevision::query()->create([
                    'recipe_id' => $target->recipe_id,
                    'revision_number' => $this->nextNumber($target->recipe_id),
                    'status' => RecipeRevisionStatus::Published->value,
                    'payload' => $payload,
                    ...$this->mediaColumns($payload, $target->media_snapshot),
                    'schema_version' => RecipeRevisionSnapshotter::SCHEMA_VERSION,
                    'content_hash' => $this->snapshotter->hash($payload),
                    'base_revision_id' => $current->getKey(),
                    'restored_from_revision_id' => $target->getKey(),
                    'created_by' => $actor->getKey(),
                    'submitted_by' => $actor->getKey(),
                    'submitted_at' => now(),
                    'review_notes' => $reason,
                    'published_by' => $actor->getKey(),
                    'published_at' => now(),
                ]),
                null,
                ['restored_from_revision_id' => $target->getKey()],
            );
        });
    }

    /**
     * Supersedes the current published revision, makes the prepared revision the published one, applies its payload and
     * verifies the live content hashes to exactly the approved hash. Any mismatch rolls the whole transaction back.
     *
     * @param  Closure(): RecipeRevision  $prepare  Returns the revision after it has been made the published one.
     * @param  array<string, mixed>|null  $metadata
     */
    private function publishPayload(int $recipeId, User $actor, string $action, ?string $note, Closure $prepare, ?RecipeRevisionStatus $from, ?array $metadata = null): RecipeRevision
    {
        return $this->guard->asPublisher($recipeId, function () use ($recipeId, $actor, $action, $note, $prepare, $from, $metadata): RecipeRevision {
            $current = RecipeRevision::query()->where('recipe_id', $recipeId)->where('status', RecipeRevisionStatus::Published->value)->lockForUpdate()->first();

            if ($current !== null) {
                $current->update(['status' => RecipeRevisionStatus::Superseded->value, 'superseded_at' => now()]);
                $this->audit($current, $actor->getKey(), 'superseded', RecipeRevisionStatus::Published, RecipeRevisionStatus::Superseded, 'Replaced by revision '.$action.'.');
            }

            $revision = $prepare();
            $this->guard->markRevision($revision->getKey());

            $payload = $this->snapshotter->normalize($revision->payload);

            if ($this->snapshotter->hash($payload) !== $revision->content_hash) {
                throw new RuntimeException("Revision {$revision->getKey()} payload no longer matches its approved hash.");
            }

            $resolved = $this->applier->apply($recipeId, $payload, $revision->getKey());
            $liveHash = $this->snapshotter->hash($this->snapshotter->serialize($recipeId));

            if ($liveHash !== $revision->content_hash) {
                throw new RuntimeException("Published content for revision {$revision->getKey()} does not match the approved content hash; publication rolled back.");
            }

            $this->assertMediaSnapshotIntact($revision, $payload);

            $revision->update(['resolved_ids' => $resolved]);
            DB::table('recipes')->where('id', $recipeId)->update(['current_revision_id' => $revision->getKey()]);
            $this->retainMedia($revision);
            $this->audit($revision, $actor->getKey(), $action, $from, RecipeRevisionStatus::Published, $note, $metadata);

            $recipe = Recipe::query()->findOrFail($recipeId);
            app(RecipeMediaStorage::class)->syncStorage($recipe);
            app(DietaryClaimService::class)->revalidate($recipe, 'A new recipe revision was published.');

            return $revision->refresh();
        }, null, 'line_removed');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array<string, mixed>>|null  $preferred
     * @return array{media_snapshot: list<array<string, mixed>>, media_snapshot_hash: string}
     */
    private function mediaColumns(array $payload, ?array $preferred): array
    {
        $snapshot = $this->snapshotter->snapshotMedia($payload, $preferred);

        return ['media_snapshot' => $snapshot, 'media_snapshot_hash' => $this->snapshotter->mediaSnapshotHash($snapshot)];
    }

    /**
     * Publication refuses a revision whose media metadata was altered or whose files no longer match what was reviewed.
     * A revision that predates snapshots gets one now, from the metadata live at the time, and the gap is audited.
     *
     * @param  array<string, mixed>  $payload
     */
    private function assertMediaSnapshotIntact(RecipeRevision $revision, array $payload): void
    {
        $snapshot = $revision->media_snapshot;

        if ($snapshot === null) {
            $revision->update($this->mediaColumns($payload, null));
            $snapshot = $revision->media_snapshot;
        }

        if ($this->snapshotter->mediaSnapshotHash($snapshot) !== $revision->media_snapshot_hash) {
            throw new RuntimeException("Media metadata of revision {$revision->getKey()} does not match its recorded hash; publication rolled back.");
        }

        $required = collect($this->snapshotter->mediaReferences($payload))->pluck('media_id')->unique();
        $held = collect($snapshot)->keyBy(fn (array $asset): int => (int) $asset['media_id']);
        $rows = DB::table('media')->whereIn('id', $required)->get()->keyBy('id');

        foreach ($required as $mediaId) {
            $row = $rows->get($mediaId);

            if ($row === null || ! $held->has($mediaId) || ! $this->snapshotter->mediaIdentityMatches($held->get($mediaId), $row)) {
                throw new RuntimeException("Media {$mediaId} no longer matches the reviewed snapshot of revision {$revision->getKey()}; publication rolled back.");
            }
        }
    }

    private function lockRecipe(int $recipeId): object
    {
        $row = DB::table('recipes')->where('id', $recipeId)->lockForUpdate()->first(['id', 'current_revision_id']);

        abort_if($row === null, 404);

        return $row;
    }

    private function lockRevision(RecipeRevision $revision): RecipeRevision
    {
        return RecipeRevision::query()->whereKey($revision->getKey())->lockForUpdate()->firstOrFail();
    }

    private function assertStatus(RecipeRevision $revision, RecipeRevisionStatus $expected): void
    {
        if ($revision->status !== $expected) {
            throw ValidationException::withMessages(['revision' => "This revision is {$revision->status->value}, not {$expected->value}."]);
        }
    }

    private function assertLive(Recipe $recipe): void
    {
        $recipe = Recipe::withTrashed()->findOrFail($recipe->getKey());

        if (! $this->isLive($recipe)) {
            throw ValidationException::withMessages(['revision' => 'The recipe is not publicly published, so nothing can be published to it.']);
        }
    }

    /**
     * A revision made against an older published version cannot silently overwrite what has been published since.
     */
    private function assertBasedOnCurrent(RecipeRevision $revision, object $recipeRow): void
    {
        if ($revision->base_revision_id === null || (int) $revision->base_revision_id !== (int) $recipeRow->current_revision_id) {
            throw ValidationException::withMessages(['revision' => 'The published recipe has changed since this revision was started. Start a new revision from the current version.']);
        }
    }

    /**
     * The base is compared in normalised form: baselines captured from legacy rows may lack empty translation rows.
     */
    private function baseHash(RecipeRevision $revision): ?string
    {
        $base = RecipeRevision::query()->whereKey($revision->base_revision_id)->first();

        return $base === null ? null : $this->snapshotter->hash($this->snapshotter->normalize($base->payload));
    }

    private function nextNumber(int $recipeId): int
    {
        return (int) RecipeRevision::query()->where('recipe_id', $recipeId)->max('revision_number') + 1;
    }

    /**
     * Records the recipe's live content as its published revision. A recipe that already has an identical published
     * revision is left alone; changed content supersedes the previous revision instead of overwriting it.
     */
    public function syncPublishedFromLive(int $recipeId, ?int $actorId = null, string $action = 'published'): RecipeRevision
    {
        return DB::transaction(function () use ($recipeId, $actorId, $action): RecipeRevision {
            $recipe = DB::table('recipes')->where('id', $recipeId)->lockForUpdate()->first(['id', 'published_at', 'current_revision_id']);

            $payload = $this->snapshotter->serialize($recipeId);
            $hash = $this->snapshotter->hash($payload);

            $current = RecipeRevision::query()->where('recipe_id', $recipeId)->where('status', RecipeRevisionStatus::Published->value)->first();

            if ($current?->content_hash === $hash) {
                if ((int) $recipe->current_revision_id !== $current->getKey()) {
                    DB::table('recipes')->where('id', $recipeId)->update(['current_revision_id' => $current->getKey()]);
                }

                return $current;
            }

            if ($current !== null) {
                $current->update(['status' => RecipeRevisionStatus::Superseded->value, 'superseded_at' => now()]);
                $this->audit($current, $actorId, 'superseded', RecipeRevisionStatus::Published, RecipeRevisionStatus::Superseded, 'Replaced by newly published live content.');
            }

            $revision = RecipeRevision::query()->create([
                'recipe_id' => $recipeId,
                'revision_number' => (int) RecipeRevision::query()->where('recipe_id', $recipeId)->max('revision_number') + 1,
                'status' => RecipeRevisionStatus::Published->value,
                'payload' => $payload,
                ...$this->mediaColumns($payload, $current?->media_snapshot),
                'schema_version' => RecipeRevisionSnapshotter::SCHEMA_VERSION,
                'content_hash' => $hash,
                'base_revision_id' => $current?->getKey(),
                'created_by' => $actorId,
                'published_by' => $actorId,
                'published_at' => $recipe->published_at ?? now(),
            ]);

            $this->retainMedia($revision);

            DB::table('recipes')->where('id', $recipeId)->update(['current_revision_id' => $revision->getKey()]);

            $this->audit($revision, $actorId, $action, null, RecipeRevisionStatus::Published, null);

            return $revision;
        });
    }

    /**
     * Pins every media item the revision shows, so none can be deleted while the revision is retained.
     */
    public function retainMedia(RecipeRevision $revision): void
    {
        foreach ($this->snapshotter->mediaReferences($revision->payload) as $reference) {
            $revision->media()->firstOrCreate($reference);
        }
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function audit(RecipeRevision $revision, ?int $actorId, string $action, ?RecipeRevisionStatus $from, ?RecipeRevisionStatus $to, ?string $note, ?array $metadata = null): RecipeRevisionAudit
    {
        return RecipeRevisionAudit::query()->create([
            'recipe_revision_id' => $revision->getKey(),
            'recipe_id' => $revision->recipe_id,
            'actor_id' => $actorId,
            'action' => $action,
            'from_status' => $from?->value,
            'to_status' => $to?->value,
            'note' => $note,
            'metadata' => $metadata,
        ]);
    }
}
