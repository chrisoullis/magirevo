<?php

namespace App\Services;

use App\Enums\RecipeRevisionStatus;
use App\Enums\TagClassification;
use App\Models\DietaryClaim;
use App\Models\Recipe;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Collection;

/**
 * The single read path for public dietary information. A claim is public only when it is approved,
 * its tag is active and enabled, the recipe is publicly accessible, and the recipe content and rule
 * version still match what was reviewed. The fingerprint is recomputed here, so a missed invalidation
 * hook can never leak a stale claim.
 */
class DietaryClaimVisibility
{
    public function __construct(private RecipeDietaryFingerprint $fingerprint) {}

    public function isCurrent(DietaryClaim $claim, ?string $liveFingerprint = null): bool
    {
        $claim->loadMissing(['tag', 'recipe']);
        $tag = $claim->tag;

        return $claim->status->value === 'approved'
            && $this->isOnCurrentPublishedRevision($claim)
            && $this->isReleased($tag)
            && $claim->reviewed_by !== null
            && $tag->is_active
            && $tag->is_public_enabled
            && $tag->classification->isDietaryClaim()
            && $claim->rule_version === $claim->currentRuleVersion()
            && hash_equals($claim->revision_hash, $liveFingerprint ?? $this->fingerprint->forId((int) $claim->recipe_id, $tag));
    }

    /**
     * Approvals never move between revisions: the claim must name the revision the recipe currently
     * publishes, and that revision must itself be in the published state.
     */
    private function isOnCurrentPublishedRevision(DietaryClaim $claim): bool
    {
        if ($claim->recipe_revision_id === null || $claim->recipe === null) {
            return false;
        }

        $claim->loadMissing('revision');

        return (int) $claim->recipe->current_revision_id === (int) $claim->recipe_revision_id
            && $claim->revision?->status === RecipeRevisionStatus::Published;
    }

    /**
     * @return Collection<int, DietaryClaim>
     */
    public function publicClaims(Recipe $recipe): Collection
    {
        if (! $recipe->isPubliclyAccessible()) {
            return new Collection;
        }

        $claims = DietaryClaim::query()->approved()->where('recipe_id', $recipe->getKey())->with(['tag.translations', 'revision'])->get();

        if ($claims->isEmpty()) {
            return $claims;
        }

        return $claims->filter(fn (DietaryClaim $claim): bool => $this->isCurrent($claim))->values();
    }

    /**
     * Editorial tags plus dietary tags backed by a current approved claim, for public display.
     *
     * @return Collection<int, Tag>
     */
    public function publicTags(Recipe $recipe): Collection
    {
        $editorial = $recipe->tags
            ->filter(fn (Tag $tag): bool => $tag->is_active && $tag->classification->isFreelyPublic());

        return $editorial
            ->concat($this->publicClaims($recipe)->map(fn (DietaryClaim $claim): Tag => $claim->tag))
            ->unique('id')
            ->sortBy('sort_order')
            ->values()
            ->pipe(fn ($tags): Collection => new Collection($tags->all()));
    }

    /**
     * Recipe ids per dietary tag code that currently hold a public claim, among published recipes.
     *
     * @return array<string, list<int>>
     */
    public function publicClaimIndex(?string $tagCode = null): array
    {
        if (! config('dietary.public_release_enabled')
            || config('dietary.public_claim_codes', []) === []) {
            return [];
        }

        $claims = DietaryClaim::query()
            ->approved()
            ->whereIn('recipe_id', Recipe::query()->published()->select('recipes.id'))
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('recipes as current_recipes')
                ->whereColumn('current_recipes.id', 'recipe_dietary_claims.recipe_id')
                ->whereColumn('current_recipes.current_revision_id', 'recipe_dietary_claims.recipe_revision_id'))
            ->whereHas('tag', fn ($tag) => $tag
                ->where('is_active', true)
                ->where('is_public_enabled', true)
                ->whereIn('classification', TagClassification::claimValues())
                ->whereIn('code', config('dietary.public_claim_codes', []))
                ->when($tagCode !== null, fn ($query) => $query->where('code', $tagCode)))
            ->with(['tag', 'recipe', 'revision'])
            ->get();

        $index = [];

        foreach ($claims as $claim) {
            if ($this->isCurrent($claim)) {
                $index[$claim->tag->code][] = (int) $claim->recipe_id;
            }
        }

        return $index;
    }

    /**
     * @return list<int>
     */
    public function recipeIdsWithPublicClaim(string $tagCode): array
    {
        return $this->publicClaimIndex($tagCode)[$tagCode] ?? [];
    }

    private function isReleased(Tag $tag): bool
    {
        return (bool) config('dietary.public_release_enabled')
            && in_array($tag->code, config('dietary.public_claim_codes', []), true);
    }
}
