<?php

namespace App\Services\Recipes;

use App\Enums\RecipeRevisionStatus;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\RecipeReusePermission;
use App\Models\RecipeRevision;
use App\Models\RecipeRevisionAudit;
use App\Models\User;
use App\Services\DietaryClaimService;
use App\Services\RecipeContentGuard;
use App\Services\RecipePublishingService;
use App\Services\RecipeRevisionService;
use App\Services\RecipeRevisionSnapshotter;
use App\Services\RecipeRevisionValidator;
use App\Support\AppPermissions;
use App\Support\TrustedVideoUrl;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RecipeVariationPublishingService
{
    public function __construct(private RecipeRevisionSnapshotter $snapshots, private RecipeRevisionValidator $validator, private RecipeContentGuard $guard) {}

    public function permission(Recipe $recipe): ?RecipeReusePermission
    {
        return RecipeReusePermission::query()->where('recipe_id', $recipe->derived_from_recipe_id)
            ->where('recipe_revision_id', $recipe->derivation?->source_recipe_revision_id)->latest('id')->first();
    }

    public function publicRights(Recipe $recipe): bool
    {
        $p = $this->permission($recipe);

        return $p !== null && app(RecipeVariationRights::class)->licenseCompatible($p, true) && $recipe->derivation?->provenance_version === 1
            && $p->mode === 'personal_and_public_submission' && $p->verification_status === 'verified'
            && $p->suspended_at === null && $p->allows_text_reproduction && $p->allows_private_adaptation
            && $p->allows_public_adaptation && ! $p->prohibits_derivatives
            && (! config('recipe-variations.commercial_context', true) || $p->allows_commercial_use)
            && (! $p->requires_attribution || $p->allows_public_attribution)
            && (! $p->requires_share_alike || (filled($p->license_identifier) && filled($p->license_url)));
    }

    public function eligible(User $actor): bool
    {
        return $actor->hasVerifiedEmail() && $actor->hasRole([AppPermissions::CONTRIBUTOR, AppPermissions::EDITOR, AppPermissions::ADMIN])
            && $actor->can(AppPermissions::RECIPES_SUBMIT) && $actor->can(AppPermissions::VARIATIONS_SUBMIT);
    }

    public function submit(User $actor, Recipe $recipe, string $version, array $declarations): RecipeRevision
    {
        return DB::transaction(function () use ($actor, $recipe, $version, $declarations): RecipeRevision {
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $recipe = Recipe::query()->whereKey($recipe->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('submitVariation', $recipe);
            if (! hash_equals(app(RecipeVariationContent::class)->version($recipe), $version)) {
                $this->fail('draft', 'stale');
            }
            if (app(RecipeRevisionService::class)->openRevision($recipe) !== null) {
                $this->fail('submission', 'review_in_progress');
            }
            $payload = $this->snapshots->normalize($this->snapshots->serialize($recipe->id));
            $revision = RecipeRevision::query()->create([
                'recipe_id' => $recipe->id, 'revision_number' => ((int) $recipe->revisions()->max('revision_number')) + 1,
                'status' => 'draft', 'payload' => $payload, 'content_hash' => $this->snapshots->hash($payload),
                'schema_version' => RecipeRevisionSnapshotter::SCHEMA_VERSION, 'created_by' => $actor->id,
            ]);

            return $this->freeze($actor, $recipe, $revision, $declarations);
        });
    }

    public function submitRevision(User $actor, RecipeRevision $revision, array $declarations): RecipeRevision
    {
        return DB::transaction(function () use ($actor, $revision, $declarations): RecipeRevision {
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $recipe = Recipe::query()->whereKey($revision->recipe_id)->lockForUpdate()->firstOrFail();
            $revision = RecipeRevision::query()->whereKey($revision->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('submit', $revision);
            abort_unless($this->eligible($actor) && $recipe->isPubliclyAccessible() && $revision->base_revision_id === $recipe->current_revision_id, 403);

            if ($this->snapshots->hash($this->snapshots->normalize($revision->payload)) === $this->snapshots->hash($this->snapshots->normalize($revision->baseRevision->payload))) {
                $this->fail('changes', 'material_changes_required');
            }

            return $this->freeze($actor, $recipe, $revision, $declarations);
        });
    }

    private function freeze(User $actor, Recipe $recipe, RecipeRevision $revision, array $declarations): RecipeRevision
    {
        abort_unless(config('recipe-variations.public_submissions_enabled'), 403);
        if ($this->guard->mode() !== 'enforce') {
            $this->fail('submission', 'enforce_required');
        }
        abort_unless($revision->status === RecipeRevisionStatus::Draft && $this->eligible($actor), 403);
        $auditSince = DB::table('recipe_revision_settings')->where('key', 'variation_edit_audit_since')->value('value');
        if ($auditSince === null || $recipe->created_at->lt(Carbon::parse($auditSince))) {
            $this->fail('submission', 'legacy_audit_required');
        }
        if (is_array($declarations['summaries'] ?? null)) {
            $declarations['summaries'] = array_map(fn ($value) => is_string($value) ? trim($value) : $value, $declarations['summaries']);
        }
        $declarations = Validator::make($declarations, [
            'rights_confirmed' => ['accepted'], 'meaningful_changes_confirmed' => ['accepted'],
            'summaries' => ['required', 'array:en,el'],
            'summaries.en' => ['required', 'string', 'min:30', 'max:5000'],
            'summaries.el' => ['required', 'string', 'min:30', 'max:5000'],
        ])->validate();
        Recipe::withTrashed()->whereKey($recipe->derived_from_recipe_id)->lockForUpdate()->firstOrFail();
        $permission = $this->permission($recipe);
        if ($permission !== null) {
            $permission = RecipeReusePermission::query()->whereKey($permission->id)->lockForUpdate()->firstOrFail();
        }
        if (! $this->publicRights($recipe)) {
            $this->fail('rights', 'publication_rights_required');
        }
        $limited = ! $actor->can(AppPermissions::RECIPES_PUBLISH);
        $rateKey = 'recipe-submission:'.$actor->id;
        if ($limited && (Recipe::query()->where('author_id', $actor->id)->where('status', 'pending')->count()
            + RecipeRevision::query()->where('submitted_by', $actor->id)->where('status', 'in_review')->whereHas('recipe', fn ($q) => $q->where('visibility', 'public'))->count() >= RecipePublishingService::MAX_PENDING_SUBMISSIONS
            || RateLimiter::tooManyAttempts($rateKey, RecipePublishingService::MAX_SUBMISSIONS_PER_DAY))) {
            throw ValidationException::withMessages(['submission' => __('review.errors.too_many_submissions')]);
        }
        $payload = $this->snapshots->normalize($revision->payload);
        $this->validator->assertSubmittable($payload, $recipe->id);
        // A correction to an existing variation need not create another materially distinct recipe.
        // The variation must still be a distinct adaptation of its permanent original source.
        $base = $recipe->derivation->sourceRevision->payload;
        if (! $this->hasMaterialChanges($base, $payload)) {
            $this->fail('changes', 'material_changes_required');
        }
        $snapshot = $this->snapshots->snapshotMedia($payload, $revision->media_snapshot);
        $fileHashes = $this->validateMedia($recipe, $payload, $snapshot);
        $involved = RecipeRevisionAudit::query()->where('recipe_id', $recipe->id)->whereNull('recipe_revision_id')->where('action', 'variation_edited')->pluck('actor_id')->filter()->map(fn ($id) => (int) $id)->push((int) $actor->id)->push((int) $recipe->author_id)->push((int) $recipe->derivation->created_by)->unique()->sort()->values()->all();
        $context = [
            'version' => 1, 'derivation_id' => $recipe->derivation->id,
            'source_revision_id' => $recipe->derivation->source_recipe_revision_id,
            'permission_id' => $permission->id, 'license_identifier' => $permission->license_identifier,
            'license_url' => $permission->license_url, 'license_conditions' => $permission->license_conditions,
            'share_alike' => $permission->requires_share_alike,
            'disclosure_allowed' => $permission->allows_public_attribution,
            'summaries' => array_map('trim', $declarations['summaries']),
            'rights_confirmed_by' => $actor->id, 'rights_confirmed_at' => now()->toIso8601String(),
            'meaningful_changes_confirmed' => true, 'involved_user_ids' => $involved, 'file_hashes' => $fileHashes,
        ];
        $revision->forceFill([
            'payload' => $payload, 'content_hash' => $this->snapshots->hash($payload),
            'media_snapshot' => $snapshot, 'media_snapshot_hash' => $this->snapshots->mediaSnapshotHash($snapshot),
            'publication_context' => $context, 'publication_context_hash' => $this->contextHash($context),
            'status' => 'in_review', 'submitted_by' => $actor->id, 'submitted_at' => now(),
        ])->save();
        app(RecipeRevisionService::class)->retainMedia($revision);
        app(DietaryClaimService::class)->bindPendingToRevision($recipe, $revision);
        app(RecipeRevisionService::class)->audit($revision, $actor->id, 'submitted', RecipeRevisionStatus::Draft, RecipeRevisionStatus::InReview, null, ['publication_context_hash' => $revision->publication_context_hash]);
        if ($revision->base_revision_id === null) {
            $recipe->forceFill(['status' => 'pending', 'submitted_at' => now(), 'rejection_reason' => null])->save();
        }

        if ($limited) {
            RateLimiter::hit($rateKey, 86400);
        }

        return $revision;
    }

    public function reviewRights(User $reviewer, RecipeRevision $revision, bool $approved, string $reason, bool $conditionsConfirmed): void
    {
        $reason = trim($reason);
        DB::transaction(function () use ($reviewer, $revision, $approved, $reason, $conditionsConfirmed): void {
            Recipe::query()->whereKey($revision->recipe_id)->lockForUpdate()->firstOrFail();
            $revision = RecipeRevision::query()->whereKey($revision->id)->lockForUpdate()->firstOrFail();
            $reviewer = User::query()->findOrFail($reviewer->id);
            Gate::forUser($reviewer)->authorize('reviewVariationRights', $revision);
            Validator::make(compact('reason', 'conditionsConfirmed'), ['reason' => ['required', 'string', 'min:20', 'max:2000'], 'conditionsConfirmed' => $approved ? ['accepted'] : ['boolean']])->validate();
            if ($approved) {
                $this->assertContext($revision);
            }
            app(RecipeRevisionService::class)->audit($revision, $reviewer->id, $approved ? 'variation_rights_cleared' : 'variation_rights_rejected', $revision->status, $revision->status, $approved ? 'Rights clearance recorded for this exact revision.' : 'Rights review declined; contact editorial staff.', [
                'private_reason' => trim($reason),
                'content_hash' => $revision->content_hash, 'publication_context_hash' => $revision->publication_context_hash,
                'permission_id' => $revision->publication_context['permission_id'], 'conditions_confirmed' => $conditionsConfirmed,
            ]);
        });
    }

    public function assertApproval(User $reviewer, RecipeRevision $revision, ?string $notes, bool $meaningfulChangesConfirmed): void
    {
        abort_unless(config('recipe-variations.public_submissions_enabled') && $reviewer->can(AppPermissions::VARIATIONS_REVIEW), 403);
        $notes = $notes === null ? null : trim($notes);
        Validator::make(['note' => $notes, 'meaningful' => $meaningfulChangesConfirmed], ['note' => ['required', 'string', 'min:20', 'max:1000'], 'meaningful' => ['accepted']])->validate();
        $this->assertContext($revision);
        $decision = $revision->audits()->whereIn('action', ['variation_rights_cleared', 'variation_rights_rejected'])->reorder()->latest('id')->first();
        if ($decision?->action !== 'variation_rights_cleared' || ($decision->metadata['publication_context_hash'] ?? null) !== $revision->publication_context_hash) {
            $this->fail('rights', 'rights_clearance_required');
        }
        if ($revision->base_revision_id === null && ($revision->recipe->status !== 'pending' || $revision->recipe->visibility->value !== 'private')) {
            $this->fail('submission', 'review_in_progress');
        }
        app(RecipeRevisionService::class)->audit($revision, $reviewer->id, 'variation_quality_confirmed', $revision->status, $revision->status, trim($notes));
    }

    private function assertContext(RecipeRevision $revision): void
    {
        if ($this->guard->mode() !== 'enforce') {
            $this->fail('submission', 'enforce_required');
        }
        $context = $revision->publication_context;
        if ($context === null || ! hash_equals($this->contextHash($context), (string) $revision->publication_context_hash)
            || ! hash_equals($this->snapshots->hash($revision->payload), $revision->content_hash)) {
            $this->fail('submission', 'frozen_content_invalid');
        }
        $recipe = $revision->recipe;
        Recipe::withTrashed()->whereKey($recipe->derived_from_recipe_id)->lockForUpdate()->firstOrFail();
        $permission = $this->permission($recipe);
        if ($permission !== null) {
            RecipeReusePermission::query()->whereKey($permission->id)->lockForUpdate()->firstOrFail();
        }
        if (! $this->publicRights($recipe) || $permission?->id !== $context['permission_id']) {
            $this->fail('rights', 'publication_rights_required');
        }
        $submitter = User::query()->find($revision->submitted_by);
        if ($submitter === null || ! $this->eligible($submitter)) {
            $this->fail('contributor', 'contributor_required');
        }
        $this->validator->assertSubmittable($revision->payload, $recipe->id);
        if ($this->validateMedia($recipe, $revision->payload, $revision->media_snapshot ?? []) !== $context['file_hashes']) {
            $this->fail('media', 'frozen_content_invalid');
        }
    }

    private function validateMedia(Recipe $recipe, array $payload, array $snapshot): array
    {
        $references = collect($this->snapshots->mediaReferences($payload))->pluck('media_id')->unique();
        foreach (array_merge(array_values($payload['translations']), array_merge(...array_map(fn ($step) => array_values($step['translations']), $payload['steps']))) as $texts) {
            foreach ($texts as $text) {
                if (is_string($text) && preg_match('/<\s*(img|iframe|video|audio|source|embed|object|script|svg|figure)\b/iu', $text)) {
                    $this->fail('media', 'source_media_forbidden');
                }
            }
        }
        $sourceAssets = $recipe->derivation->sourceRevision->media_snapshot ?? [];
        $sourceIds = collect($this->snapshots->mediaReferences($recipe->derivation->sourceRevision->payload))->pluck('media_id');
        $files = [];
        foreach ($references as $id) {
            $media = Media::query()->find($id);
            $asset = collect($snapshot)->firstWhere('media_id', $id);
            if ($media === null || $asset === null || ! $this->snapshots->mediaIdentityMatches($asset, $media)
                || $sourceIds->contains($id) || $media->recipes()->where('recipes.id', '<>', $recipe->id)->exists()
                || $media->recipeSteps()->where('recipe_id', '<>', $recipe->id)->exists() || $media->categories()->exists()) {
                $this->fail('media', 'source_media_forbidden');
            }
            $attached = $media->recipes()->whereKey($recipe->id)->exists() || $media->recipeSteps()->where('recipe_id', $recipe->id)->exists()
                || DB::table('recipe_revision_media')->join('recipe_revisions', 'recipe_revisions.id', '=', 'recipe_revision_media.recipe_revision_id')
                    ->where('media_id', $id)->where('recipe_revisions.recipe_id', $recipe->id)->whereIn('recipe_revisions.status', ['published', 'superseded'])->exists();
            if (! $attached) {
                $this->fail('media', 'source_media_forbidden');
            }
            if ($media->media_type === 'image') {
                if (! $media->isLocal() || ! Storage::disk($media->disk)->exists($media->file_path)) {
                    $this->fail('media', 'new_cover_required');
                }
                $hash = hash_file('sha256', Storage::disk($media->disk)->path($media->file_path));
                foreach ($sourceAssets as $original) {
                    if (($original['source'] ?? null) === 'local' && filled($original['file_path'] ?? null)) {
                        foreach (['local', 'public'] as $disk) {
                            if (Storage::disk($disk)->exists($original['file_path']) && hash_equals($hash, hash_file('sha256', Storage::disk($disk)->path($original['file_path'])))) {
                                $this->fail('media', 'source_media_forbidden');
                            }
                        }
                    }
                }
                $files[(string) $id] = $hash;
            } elseif ($media->media_type !== 'video' || TrustedVideoUrl::embedUrl((string) $media->url) === null || collect($sourceAssets)->contains(fn ($original) => ($original['url'] ?? null) === $media->url)) {
                $this->fail('media', 'source_media_forbidden');
            }
        }
        $cover = collect($payload['media'])->firstWhere('role', 'cover');
        if ($cover === null || ! array_key_exists((string) $cover['media_id'], $files)) {
            $this->fail('media', 'new_cover_required');
        }
        ksort($files);

        return $files;
    }

    private function hasMaterialChanges(array $before, array $after): bool
    {
        $signature = function (array $payload): array {
            $base = max(0.01, (float) $payload['recipe']['base_servings']);
            $ingredients = array_map(fn ($line) => [$line['ingredient_id'], $line['unit_id'], $line['scaling_mode'], $line['is_optional'], $line['quantity'] === null ? null : round((float) $line['quantity'] / $base, 8), $line['quantity_max'] === null ? null : round((float) $line['quantity_max'] / $base, 8)], $payload['ingredients']);
            sort($ingredients);
            $steps = array_map(fn ($step) => array_map(fn ($text) => trim(preg_replace('/\s+/u', ' ', app(RecipeVariationText::class)->clean($text['instruction'] ?? ''))), $step['translations']), $payload['steps']);

            return [$ingredients, $steps, array_intersect_key($payload['recipe'], array_flip(['prep_minutes', 'cook_minutes', 'rest_minutes']))];
        };

        return $signature($this->snapshots->normalize($before)) !== $signature($this->snapshots->normalize($after));
    }

    public function restoreAsDraft(User $actor, RecipeRevision $target, string $reason): RecipeRevision
    {
        $service = app(RecipeRevisionService::class);
        if ($target->id === $target->recipe->current_revision_id) {
            $this->fail('revision', 'material_changes_required');
        }
        $draft = $service->createDraft($actor, $target->recipe);
        $payload = $this->snapshots->normalize($this->snapshots->rebaseIds($target->payload, $target->recipe_id));
        $draft = $service->saveDraft($actor, $draft, $payload);
        $snapshot = $this->snapshots->snapshotMedia($payload, $target->media_snapshot);
        $draft->update(['restored_from_revision_id' => $target->id, 'media_snapshot' => $snapshot, 'media_snapshot_hash' => $this->snapshots->mediaSnapshotHash($snapshot)]);
        $service->audit($draft, $actor->id, 'restored', RecipeRevisionStatus::Draft, RecipeRevisionStatus::Draft, $reason, ['restored_from_revision_id' => $target->id]);

        return $draft;
    }

    public function contextHash(array $context): string
    {
        $sort = function (array $values) use (&$sort): array {
            foreach ($values as &$value) {
                if (is_array($value)) {
                    $value = $sort($value);
                }
            }
            if (! array_is_list($values)) {
                ksort($values);
            }

            return $values;
        };

        return hash('sha256', json_encode($sort($context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function withdraw(User $actor, Recipe $recipe): Recipe
    {
        return DB::transaction(function () use ($actor, $recipe): Recipe {
            $recipe = Recipe::query()->whereKey($recipe->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('withdraw', $recipe);
            $revision = $recipe->revisions()->where('status', 'in_review')->whereNull('base_revision_id')->lockForUpdate()->firstOrFail();
            $revision->update(['status' => 'rejected', 'rejection_reason' => __('variations.withdrawn')]);
            $this->guard->asPublisher($recipe->id, fn () => DB::table('recipes')->where('id', $recipe->id)->update(['status' => 'draft', 'visibility' => 'private']));
            app(RecipeRevisionService::class)->audit($revision, $actor->id, 'withdrawn', RecipeRevisionStatus::InReview, RecipeRevisionStatus::Rejected, __('variations.withdrawn'));

            return $recipe->refresh();
        });
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => __('variations.'.$message)]);
    }
}
