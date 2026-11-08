<?php

namespace App\Services;

use App\Actions\DeleteMediaIfUnused;
use App\Enums\RecipeRevisionStatus;
use App\Enums\RecipeVisibility;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\RecipeRevision;
use App\Models\RecipeRevisionAudit;
use App\Models\User;
use App\Services\Recipes\RecipeVariationPublishingService;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
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
        if (in_array((int) $user->id, $revision->publication_context['involved_user_ids'] ?? [], true)) {
            return true;
        }

        $id = (int) $user->getKey();
        if ($revision->recipe->derived_from_recipe_id !== null && RecipeRevisionAudit::query()->where('recipe_id', $revision->recipe_id)->whereNull('recipe_revision_id')->where('actor_id', $id)->where('action', 'variation_edited')->exists()) {
            return true;
        }

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
                    : $this->snapshotter->rebaseIds($current->payload, $recipe->getKey());
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

    /** Capture readable private content without changing its visibility or published pointer. */
    public function captureComponentSnapshot(User $actor, Recipe $recipe): RecipeRevision
    {
        $this->authorizeComponentCapture($actor, $recipe);

        return DB::transaction(function () use ($actor, $recipe): RecipeRevision {
            $this->lockRecipe($recipe->getKey());
            $recipe->refresh();
            $this->authorizeComponentCapture($actor, $recipe);
            $payload = $this->snapshotter->serialize($recipe->getKey());
            app(RecipeComponentResolver::class)->assertValidPayload($payload, $recipe->getKey(), $actor, false);
            $hash = $this->snapshotter->hash($payload);
            $media = $this->mediaColumns($payload, null);
            $existing = RecipeRevision::query()->where('recipe_id', $recipe->getKey())
                ->where('status', RecipeRevisionStatus::Retained->value)
                ->where('schema_version', RecipeRevisionSnapshotter::SCHEMA_VERSION)
                ->where('content_hash', $hash)
                ->where('media_snapshot_hash', $media['media_snapshot_hash'])
                ->orderByDesc('revision_number')->first();

            if ($existing !== null) {
                return $existing;
            }

            // The insert trigger records dependencies atomically, without occupying the open-draft slot.
            $revision = RecipeRevision::query()->create([
                'recipe_id' => $recipe->getKey(),
                'revision_number' => $this->nextNumber($recipe->getKey()),
                'status' => RecipeRevisionStatus::Retained->value,
                'payload' => $payload,
                ...$media,
                'schema_version' => RecipeRevisionSnapshotter::SCHEMA_VERSION,
                'content_hash' => $hash,
                'created_by' => $actor->getKey(),
            ]);
            $this->retainMedia($revision);
            $this->audit($revision, $actor->getKey(), 'retained', null, RecipeRevisionStatus::Retained, null);

            return $revision;
        });
    }

    private function authorizeComponentCapture(User $actor, Recipe $recipe): void
    {
        // Capture validates the graph separately so authorized owners receive actionable errors.
        // Public reads still use the policy's complete, fail-closed graph check.
        if (! app(RecipeAccessService::class)->canViewDirect($actor, $recipe)) {
            throw new AuthorizationException;
        }
    }

    /** Must run while the parent is still a draft, before its graph becomes immutable. */
    public function indexDependencies(RecipeRevision $revision, ?array $payload = null): void
    {
        $this->assertStatus($revision, RecipeRevisionStatus::Draft);
        if (! Schema::hasTable('recipe_revision_dependencies')) {
            return;
        }
        DB::table('recipe_revision_dependencies')->where('recipe_revision_id', $revision->getKey())->delete();
        foreach (($payload ?? $revision->payload)['ingredients'] ?? [] as $line) {
            if (($line['line_type'] ?? 'ingredient') !== 'recipe') {
                continue;
            }
            DB::table('recipe_revision_dependencies')->insert([
                'recipe_revision_id' => $revision->getKey(),
                'line_uuid' => $line['line_uuid'],
                'component_recipe_id' => $line['component_recipe_id'],
                'component_recipe_revision_id' => $line['component_recipe_revision_id'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** Creates dependency rows before freezing a newly published revision. */
    private function createPublished(array $attributes): RecipeRevision
    {
        // Empty graphs need no draft phase and must not conflict with an existing editorial draft.
        if (! collect($attributes['payload']['ingredients'] ?? [])->contains(fn (array $line): bool => ($line['line_type'] ?? 'ingredient') === 'recipe')) {
            return RecipeRevision::query()->create($attributes);
        }
        $revision = RecipeRevision::query()->create([...$attributes, 'status' => RecipeRevisionStatus::Draft->value]);
        $this->indexDependencies($revision);
        $revision->update(['status' => RecipeRevisionStatus::Published->value]);

        return $revision;
    }

    public function draftVersion(RecipeRevision $revision): string
    {
        return hash('sha256', $revision->content_hash.':'.$revision->media_snapshot_hash);
    }

    /** @param array<string, mixed> $payload */
    public function saveDraft(User $actor, RecipeRevision $revision, array $payload, ?string $expectedVersion = null): RecipeRevision
    {
        Gate::forUser($actor)->authorize('edit', $revision);

        return DB::transaction(function () use ($actor, $revision, $payload, $expectedVersion): RecipeRevision {
            $this->lockRecipe($revision->recipe_id);
            $revision = $this->lockRevision($revision);
            $this->assertStatus($revision, RecipeRevisionStatus::Draft);

            if ($expectedVersion !== null && ! hash_equals($this->draftVersion($revision), $expectedVersion)) {
                throw ValidationException::withMessages(['draft' => __('editor.stale')]);
            }

            // Older editors omit v2 metadata. Keep the pinned graph and durable identities when absent.
            foreach (['yields', 'units'] as $field) {
                if (! array_key_exists($field, $payload)) {
                    $payload[$field] = $revision->payload[$field] ?? [];
                }
            }
            $previous = collect($revision->payload['ingredients'] ?? [])->filter(fn (array $line): bool => isset($line['id']))->keyBy('id');
            foreach ($payload['ingredients'] ?? [] as $index => $line) {
                if (isset($line['id']) && $previous->has($line['id'])) {
                    $payload['ingredients'][$index] = [...$previous->get($line['id']), ...$line];
                }
            }
            $normalized = $this->snapshotter->normalize($payload);
            $hash = $this->snapshotter->hash($normalized);
            $media = $this->mediaColumns($normalized, $revision->media_snapshot);

            if ($hash === $revision->content_hash && $media['media_snapshot_hash'] === $revision->media_snapshot_hash) {
                return $revision;
            }

            $revision->update(['payload' => $normalized, 'schema_version' => RecipeRevisionSnapshotter::SCHEMA_VERSION, 'content_hash' => $hash, ...$media]);
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
            $media = Media::query()->whereIn('id', $revision->media()->pluck('media_id'))->get();
            $revision->delete();
            foreach ($media as $asset) {
                app(DeleteMediaIfUnused::class)->handle($asset);
            }
        });
    }

    /**
     * Validates and freezes the draft for editorial review. From here the payload cannot change.
     */
    public function submit(User $actor, RecipeRevision $revision, array $declarations = []): RecipeRevision
    {
        if ($revision->recipe->derived_from_recipe_id !== null) {
            return app(RecipeVariationPublishingService::class)->submitRevision($actor, $revision, $declarations);
        }

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

            $this->validator->assertSubmittable($payload, $revision->recipe_id, $actor, true);
            $this->indexDependencies($revision, $payload);

            $revision->update([
                'payload' => $payload,
                'schema_version' => RecipeRevisionSnapshotter::SCHEMA_VERSION,
                ...$this->mediaColumns($payload, $revision->media_snapshot),
                'content_hash' => $this->snapshotter->hash($payload),
                'status' => RecipeRevisionStatus::InReview->value,
                'submitted_by' => $actor->getKey(),
                'submitted_at' => now(),
            ]);

            $this->retainMedia($revision);
            $this->audit($revision, $actor->getKey(), 'submitted', RecipeRevisionStatus::Draft, RecipeRevisionStatus::InReview, null);

            return $revision;
        }, 5);
    }

    /**
     * Approval publishes: the approved payload is applied to the live recipe in one transaction and rolled back if the
     * resulting live content does not hash to exactly what was reviewed.
     */
    public function approve(User $reviewer, RecipeRevision $revision, ?string $notes = null, bool $meaningfulChangesConfirmed = false): RecipeRevision
    {
        Gate::forUser($reviewer)->authorize('approve', $revision);

        return DB::transaction(function () use ($reviewer, $revision, $notes, $meaningfulChangesConfirmed): RecipeRevision {
            $recipeRow = $this->lockRecipe($revision->recipe_id);
            $revision = $this->lockRevision($revision);
            $this->assertStatus($revision, RecipeRevisionStatus::InReview);
            $reviewer = User::query()->findOrFail($reviewer->id);
            Gate::forUser($reviewer)->authorize('approve', $revision);
            $variation = $revision->recipe->derived_from_recipe_id !== null;
            $firstPublication = $variation && $revision->base_revision_id === null && $recipeRow->current_revision_id === null;
            if ($variation) {
                app(RecipeVariationPublishingService::class)->assertApproval($reviewer, $revision, $notes, $meaningfulChangesConfirmed);
            }
            if (! $firstPublication) {
                $this->assertLive($revision->recipe);
                $this->assertBasedOnCurrent($revision, $recipeRow);
            }
            $this->validator->assertSubmittable($this->snapshotter->normalize($revision->payload), $revision->recipe_id, $reviewer, true);

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
        }, 5);
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
            $reviewer = User::query()->findOrFail($reviewer->id);
            Gate::forUser($reviewer)->authorize('reject', $revision);

            $revision->update([
                'status' => RecipeRevisionStatus::Rejected->value,
                'reviewed_by' => $reviewer->getKey(),
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ]);

            if ($revision->publication_context !== null && $revision->base_revision_id === null) {
                $this->guard->asPublisher($revision->recipe_id, fn () => DB::table('recipes')->where('id', $revision->recipe_id)->update(['status' => 'draft', 'visibility' => 'private', 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'rejection_reason' => $reason]));
            }
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
            if ($target->recipe->derived_from_recipe_id !== null) {
                return app(RecipeVariationPublishingService::class)->restoreAsDraft($actor, $target, $reason);
            }
            $this->assertLive($target->recipe);

            $current = RecipeRevision::query()->where('recipe_id', $target->recipe_id)->where('status', RecipeRevisionStatus::Published->value)->first();

            if ($current === null || (int) $recipeRow->current_revision_id !== $current->getKey()) {
                throw ValidationException::withMessages(['revision' => 'The recipe has no consistent published revision to roll back from.']);
            }

            if ($current->is($target) || $current->content_hash === $target->content_hash) {
                throw ValidationException::withMessages(['revision' => 'That revision is already the published content.']);
            }

            $payload = $this->snapshotter->normalize($this->snapshotter->rebaseIds($target->payload, $target->recipe_id));
            $this->validator->assertSubmittable($payload, $target->recipe_id, $actor, true);

            return $this->publishPayload(
                $target->recipe_id,
                $actor,
                'rolled_back',
                $reason,
                fn (): RecipeRevision => $this->createPublished([
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
        }, 5);
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

            $payload = $revision->payload;

            if ($this->snapshotter->hash($payload) !== $revision->content_hash) {
                throw new RuntimeException("Revision {$revision->getKey()} payload no longer matches its approved hash.");
            }

            $resolved = $this->applier->apply($recipeId, $this->snapshotter->forSchema($this->snapshotter->normalize($payload), $revision->schema_version), $revision->getKey());
            $liveHash = $this->snapshotter->hash($this->snapshotter->forSchema($this->snapshotter->serialize($recipeId, $revision->payload['units'] ?? []), $revision->schema_version));

            if ($liveHash !== $revision->content_hash) {
                throw new RuntimeException("Published content for revision {$revision->getKey()} does not match the approved content hash; publication rolled back.");
            }

            $this->assertMediaSnapshotIntact($revision, $payload);

            $revision->update(['resolved_ids' => $resolved]);
            $updates = ['current_revision_id' => $revision->getKey()];
            if ($revision->publication_context !== null) {
                $updates += ['status' => 'published', 'visibility' => 'public', 'published_at' => $revision->recipe->published_at ?? now(), 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'rejection_reason' => null];
            }
            DB::table('recipes')->where('id', $recipeId)->update($updates);
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
            app(RecipeComponentResolver::class)->assertValidPayload($payload, $recipeId, $actorId !== null ? User::find($actorId) : null, true);
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

            $revision = $this->createPublished([
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
        }, 5);
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
