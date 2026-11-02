<?php

namespace App\Services;

use App\Enums\DietaryClaimAction;
use App\Enums\DietaryClaimStatus;
use App\Enums\DietaryCompatibility;
use App\Enums\DietaryVerificationMethod;
use App\Enums\RecipeRevisionStatus;
use App\Models\DietaryClaim;
use App\Models\DietaryClaimAudit;
use App\Models\Recipe;
use App\Models\RecipeRevision;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of claim state. Every transition is authorized, runs in a transaction and is audited.
 * Approval by an administrator records an editorial decision; it is not professional certification and
 * never happens automatically.
 */
class DietaryClaimService
{
    /** @var array<int, list<string>> */
    private static array $pendingRevalidation = [];

    public function __construct(
        private RecipeDietaryFingerprint $fingerprint,
        private IngredientDietaryResolver $resolver,
    ) {}

    /**
     * @param  list<string>  $evidence
     */
    public function suggest(User $actor, Recipe $recipe, Tag $tag, ?DietaryVerificationMethod $method = null, array $evidence = [], ?string $notes = null, ?RecipeRevision $revision = null): DietaryClaim
    {
        Gate::forUser($actor)->authorize('suggest', [DietaryClaim::class, $recipe]);

        if (! $tag->is_active || ! $tag->isDietaryClaim()) {
            throw ValidationException::withMessages(['tag' => 'This tag is not a dietary claim.']);
        }

        $revision = $this->targetRevision($recipe, $revision);

        return DB::transaction(function () use ($actor, $recipe, $tag, $method, $evidence, $notes, $revision): DietaryClaim {
            $claim = DietaryClaim::query()
                ->where('recipe_id', $recipe->getKey())
                ->where('tag_id', $tag->getKey())
                ->when($revision === null, fn ($query) => $query->whereNull('recipe_revision_id'), fn ($query) => $query->where('recipe_revision_id', $revision->getKey()))
                ->lockForUpdate()
                ->first() ?? new DietaryClaim(['recipe_id' => $recipe->getKey(), 'recipe_revision_id' => $revision?->getKey(), 'tag_id' => $tag->getKey()]);

            $claim->setRelation('tag', $tag)->setRelation('recipe', $recipe)->setRelation('revision', $revision);
            $previous = $claim->exists ? $claim->status : null;

            if ($previous === DietaryClaimStatus::Approved && $this->isStillValid($claim)) {
                throw ValidationException::withMessages(['claim' => 'This claim is already approved for the current recipe.']);
            }

            $claim->fill([
                'status' => DietaryClaimStatus::Suggested,
                'suggested_by' => $actor->getKey(),
                'reviewed_by' => null,
                'reviewed_at' => null,
                'verification_method' => $method,
                'evidence' => $this->cleanEvidence($evidence),
                'revision_hash' => $this->fingerprint->forClaim($claim),
                'rule_version' => $claim->currentRuleVersion(),
                'review_notes' => $notes,
                'suspended_at' => null,
                'suspension_reason' => null,
            ])->save();

            $this->audit($claim, DietaryClaimAction::Suggested, $previous, $actor->getKey(), $notes);

            return $claim;
        });
    }

    /**
     * @param  list<string>  $evidence
     */
    public function approve(User $reviewer, DietaryClaim $claim, DietaryVerificationMethod $method, array $evidence = [], ?string $notes = null): DietaryClaim
    {
        return DB::transaction(function () use ($reviewer, $claim, $method, $evidence, $notes): DietaryClaim {
            $claim = $this->lock($claim);
            Gate::forUser($reviewer)->authorize('approve', $claim);

            if ($claim->status !== DietaryClaimStatus::Suggested) {
                throw ValidationException::withMessages(['claim' => 'Only suggested claims can be approved.']);
            }

            $this->assertApprovableRevision($reviewer, $claim);

            if ($claim->revision_hash !== $this->fingerprint->forClaim($claim) || $claim->rule_version !== $claim->currentRuleVersion()) {
                throw ValidationException::withMessages(['claim' => 'The recipe or the rules changed after this claim was suggested. Suggest it again for review.']);
            }

            if (! $claim->tag->is_active) {
                throw ValidationException::withMessages(['claim' => 'The tag is inactive.']);
            }

            $evidence = $this->cleanEvidence($evidence) ?: ($claim->evidence ?? []);

            if ($claim->isHighRisk() && (! $method->isQualified() || $evidence === [])) {
                throw ValidationException::withMessages(['evidence' => 'Gluten-free, allergen and nutrition claims need supporting evidence and a qualified verification method.']);
            }

            if (in_array($claim->tag->code, ['vegetarian', 'vegan'], true)
                && $this->resolver->resolve($claim->contentSubject(), $claim->tag) !== DietaryCompatibility::Compatible) {
                throw ValidationException::withMessages(['claim' => 'Ingredient evidence is conflicting, incomplete, unreviewed, or insufficient for this classification.']);
            }

            $previous = $claim->status;
            $claim->fill([
                'status' => DietaryClaimStatus::Approved,
                'reviewed_by' => $reviewer->getKey(),
                'reviewed_at' => now(),
                'verification_method' => $method,
                'evidence' => $evidence,
                'review_notes' => $notes ?? $claim->review_notes,
                'suspended_at' => null,
                'suspension_reason' => null,
            ])->save();

            $this->audit($claim, DietaryClaimAction::Approved, $previous, $reviewer->getKey(), $notes);

            return $claim;
        });
    }

    public function reject(User $reviewer, DietaryClaim $claim, string $notes): DietaryClaim
    {
        return DB::transaction(function () use ($reviewer, $claim, $notes): DietaryClaim {
            $claim = $this->lock($claim);
            Gate::forUser($reviewer)->authorize('reject', $claim);

            if ($claim->status !== DietaryClaimStatus::Suggested) {
                throw ValidationException::withMessages(['claim' => 'Only suggested claims can be rejected.']);
            }

            if (blank($notes)) {
                throw ValidationException::withMessages(['notes' => 'A reason is required.']);
            }

            $previous = $claim->status;
            $claim->fill(['status' => DietaryClaimStatus::Rejected, 'reviewed_by' => $reviewer->getKey(), 'reviewed_at' => now(), 'review_notes' => $notes])->save();
            $this->audit($claim, DietaryClaimAction::Rejected, $previous, $reviewer->getKey(), $notes);

            return $claim;
        });
    }

    public function suspend(User $actor, DietaryClaim $claim, string $reason): DietaryClaim
    {
        return DB::transaction(function () use ($actor, $claim, $reason): DietaryClaim {
            $claim = $this->lock($claim);
            Gate::forUser($actor)->authorize('suspend', $claim);

            if ($claim->status !== DietaryClaimStatus::Approved) {
                throw ValidationException::withMessages(['claim' => 'Only approved claims can be suspended.']);
            }

            if (blank($reason)) {
                throw ValidationException::withMessages(['reason' => 'A reason is required.']);
            }

            $previous = $claim->status;
            $claim->fill(['status' => DietaryClaimStatus::Suspended, 'suspended_at' => now(), 'suspension_reason' => $reason])->save();
            $this->audit($claim, DietaryClaimAction::Suspended, $previous, $actor->getKey(), $reason);

            return $claim;
        });
    }

    /**
     * Records a problem report without changing the claim, for staff who may not approve claims.
     */
    public function flag(User $actor, DietaryClaim $claim, string $notes): DietaryClaimAudit
    {
        Gate::forUser($actor)->authorize('flag', $claim);

        if (blank($notes)) {
            throw ValidationException::withMessages(['notes' => 'Describe the problem.']);
        }

        return $this->audit($claim, DietaryClaimAction::Flagged, $claim->status, $actor->getKey(), $notes);
    }

    public function report(User $reporter, DietaryClaim $claim, string $notes): DietaryClaimAudit
    {
        Gate::forUser($reporter)->authorize('report', $claim);

        if (blank($notes)) {
            throw ValidationException::withMessages(['notes' => 'Describe the problem.']);
        }

        return $this->audit($claim, DietaryClaimAction::Flagged, $claim->status, $reporter->getKey(), mb_substr(trim($notes), 0, 1000));
    }

    /**
     * Invalidates approved claims whose recipe content or rule version no longer matches what was reviewed.
     * Returns the number of claims invalidated.
     */
    public function revalidate(Recipe $recipe, string $reason = 'Recipe ingredient, quantity, preparation, evidence or verification rules changed.'): int
    {
        $invalidated = 0;
        DietaryClaim::query()->approved()->where('recipe_id', $recipe->getKey())->with(['tag', 'recipe', 'revision'])->get()
            ->each(function (DietaryClaim $claim) use ($reason, &$invalidated): void {
                $hash = $this->fingerprint->forClaim($claim);

                if ($claim->revision_hash === $hash && $claim->rule_version === $claim->currentRuleVersion()) {
                    return;
                }

                DB::transaction(function () use ($claim, $reason, &$invalidated): void {
                    $claim = $this->lock($claim);

                    if ($claim->status !== DietaryClaimStatus::Approved) {
                        return;
                    }

                    $claim->update(['status' => DietaryClaimStatus::Invalidated]);
                    $invalidReason = $claim->rule_version !== $claim->currentRuleVersion()
                        ? 'The dietary verification rule version changed.'
                        : $reason;
                    $this->audit($claim, DietaryClaimAction::Invalidated, DietaryClaimStatus::Approved, Auth::id(), $invalidReason);
                    $invalidated++;
                });
            });

        return $invalidated;
    }

    /**
     * First publication of a recipe: pending proposals made before any revision existed are bound to the
     * baseline revision. They keep their original content hash, so a proposal that no longer matches the
     * published content cannot be approved. Nothing else is carried over.
     */
    public function bindPendingToRevision(Recipe $recipe, RecipeRevision $revision): int
    {
        $bound = 0;

        DB::transaction(function () use ($recipe, $revision, &$bound): void {
            DietaryClaim::query()
                ->where('recipe_id', $recipe->getKey())
                ->whereNull('recipe_revision_id')
                ->where('status', DietaryClaimStatus::Suggested->value)
                ->lockForUpdate()
                ->get()
                ->each(function (DietaryClaim $claim) use ($revision, &$bound): void {
                    if (DietaryClaim::query()->where('recipe_revision_id', $revision->getKey())->where('tag_id', $claim->tag_id)->exists()) {
                        return;
                    }

                    $claim->update(['recipe_revision_id' => $revision->getKey()]);
                    $this->audit($claim, DietaryClaimAction::Suggested, DietaryClaimStatus::Suggested, Auth::id(), "Proposal bound to revision {$revision->revision_number} on publication.");
                    $bound++;
                });
        });

        return $bound;
    }

    public function revalidateAll(): int
    {
        $total = 0;

        DietaryClaim::query()->approved()->distinct()->pluck('recipe_id')->each(function (int $recipeId) use (&$total): void {
            $recipe = Recipe::withTrashed()->find($recipeId);

            if ($recipe !== null) {
                $total += $this->revalidate($recipe);
            }
        });

        return $total;
    }

    /**
     * Debounced: many line edits in one save revalidate the recipe once, after the transaction commits.
     */
    public function scheduleRevalidation(int $recipeId, string $reason = 'Recipe ingredient, quantity or preparation content changed.'): void
    {
        if (isset(self::$pendingRevalidation[$recipeId])) {
            if (! in_array($reason, self::$pendingRevalidation[$recipeId], true)) {
                self::$pendingRevalidation[$recipeId][] = $reason;
            }

            return;
        }

        self::$pendingRevalidation[$recipeId] = [$reason];

        DB::afterCommit(function () use ($recipeId): void {
            $reasons = self::$pendingRevalidation[$recipeId] ?? [];
            unset(self::$pendingRevalidation[$recipeId]);

            $recipe = Recipe::withTrashed()->find($recipeId);

            if ($recipe !== null) {
                $this->revalidate($recipe, implode(' ', $reasons));
            }
        });
    }

    private function isStillValid(DietaryClaim $claim): bool
    {
        return $claim->revision_hash === $this->fingerprint->forClaim($claim)
            && $claim->rule_version === $claim->currentRuleVersion();
    }

    /**
     * A recipe that is live is reviewed against its current published revision; anything else is a
     * pre-publication proposal with no revision yet. An explicit revision must belong to the recipe.
     */
    private function targetRevision(Recipe $recipe, ?RecipeRevision $revision): ?RecipeRevision
    {
        if ($revision !== null) {
            if ((int) $revision->recipe_id !== (int) $recipe->getKey()) {
                throw ValidationException::withMessages(['revision' => 'The revision does not belong to this recipe.']);
            }

            if (! in_array($revision->status, [RecipeRevisionStatus::Draft, RecipeRevisionStatus::InReview, RecipeRevisionStatus::Published], true)) {
                throw ValidationException::withMessages(['revision' => 'Claims can only be proposed for open or currently published revisions.']);
            }

            return $revision;
        }

        return $recipe->isPubliclyAccessible() && $recipe->current_revision_id !== null
            ? RecipeRevision::query()->find($recipe->current_revision_id)
            : null;
    }

    /**
     * Only frozen content can be approved: an in-review revision, or the current published one. A draft can
     * still change, and a superseded or rejected revision is history. The person who authored or submitted
     * a revision cannot approve its claims.
     */
    private function assertApprovableRevision(User $reviewer, DietaryClaim $claim): void
    {
        $revision = $claim->revision;

        if ($revision === null) {
            throw ValidationException::withMessages(['claim' => 'This proposal is not bound to a recipe revision yet. Publish the recipe, then review the claim.']);
        }

        $isCurrent = (int) $claim->recipe->current_revision_id === (int) $revision->getKey() && $revision->status === RecipeRevisionStatus::Published;

        if (! $isCurrent && $revision->status !== RecipeRevisionStatus::InReview) {
            throw ValidationException::withMessages(['claim' => 'Only claims on a revision that is in review or currently published can be approved.']);
        }

        if ($revision->status === RecipeRevisionStatus::InReview && app(RecipeRevisionService::class)->isInvolved($revision, $reviewer)) {
            throw ValidationException::withMessages(['claim' => 'You contributed to this revision and cannot approve its claims.']);
        }
    }

    private function lock(DietaryClaim $claim): DietaryClaim
    {
        return DietaryClaim::query()->with(['tag', 'recipe', 'revision'])->lockForUpdate()->findOrFail($claim->getKey());
    }

    private function audit(DietaryClaim $claim, DietaryClaimAction $action, ?DietaryClaimStatus $previous, int|string|null $actorId, ?string $notes = null): DietaryClaimAudit
    {
        return DietaryClaimAudit::create([
            'dietary_claim_id' => $claim->getKey(),
            'recipe_id' => $claim->recipe_id,
            'recipe_revision_id' => $claim->recipe_revision_id,
            'tag_id' => $claim->tag_id,
            'action' => $action,
            'previous_status' => $previous?->value,
            'new_status' => $claim->status->value,
            'actor_id' => $actorId,
            'verification_method' => $claim->verification_method?->value,
            'evidence_reference' => $claim->evidence,
            'notes' => $notes,
            'revision_hash' => $claim->revision_hash,
            'rule_version' => $claim->rule_version,
        ]);
    }

    /**
     * @param  array<int, mixed>  $evidence
     * @return list<string>
     */
    private function cleanEvidence(array $evidence): array
    {
        return collect($evidence)
            ->filter(fn (mixed $item): bool => is_string($item) && trim($item) !== '')
            ->map(fn (string $item): string => mb_substr(trim($item), 0, 500))
            ->values()
            ->all();
    }
}
