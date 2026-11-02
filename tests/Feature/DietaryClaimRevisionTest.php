<?php

namespace Tests\Feature;

use App\Enums\DietaryClaimStatus;
use App\Enums\DietaryCompatibility;
use App\Enums\DietaryVerificationMethod;
use App\Enums\IngredientCompositionStatus;
use App\Enums\IngredientOriginClassification;
use App\Enums\RecipeRevisionStatus;
use App\Livewire\RecipeIndex;
use App\Models\DietaryClaim;
use App\Models\DietaryClaimAudit;
use App\Models\IngredientComponent;
use App\Models\IngredientDietaryAssessment;
use App\Models\IngredientDietaryProfile;
use App\Models\Recipe;
use App\Models\RecipeRevision;
use App\Models\Tag;
use App\Models\User;
use App\Services\DietaryClaimService;
use App\Services\DietaryClaimVisibility;
use App\Services\RecipeDietaryFingerprint;
use App\Services\RecipePublishingService;
use App\Services\RecipeRevisionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Dietary claims are bound to the exact recipe revision they were reviewed against. These tests check the
 * software behaviour only; they are not evidence that any recipe is nutritionally or legally suitable.
 */
class DietaryClaimRevisionTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    private User $admin;

    private Tag $tag;

    private Recipe $recipe;

    protected function setUp(): void
    {
        parent::setUp();

        config(['dietary.public_release_enabled' => true, 'dietary.public_claim_codes' => ['vegetarian']]);

        $this->author = User::factory()->contributor()->create();
        $this->admin = User::factory()->admin()->create();
        $this->tag = Tag::factory()->dietary('diet_style', true)->create(['code' => 'vegetarian']);
        $this->tag->translations()->createMany([
            ['locale' => 'en', 'name' => 'Vegetarian Label', 'slug' => 'vegetarian-en'],
            ['locale' => 'el', 'name' => 'Χορτοφαγικό Label', 'slug' => 'vegetarian-el'],
        ]);

        $this->recipe = Recipe::factory()->complete()->create(['author_id' => $this->author->id]);
        $this->publish($this->recipe);
        $this->recipe->refresh();
    }

    private function publish(Recipe $recipe): void
    {
        $this->actingAs($this->admin);
        app(RecipePublishingService::class)->publish($recipe);
    }

    private function claims(): DietaryClaimService
    {
        return app(DietaryClaimService::class);
    }

    private function revisions(): RecipeRevisionService
    {
        return app(RecipeRevisionService::class);
    }

    private function visibility(): DietaryClaimVisibility
    {
        return app(DietaryClaimVisibility::class);
    }

    private function reviewer(): User
    {
        return User::factory()->admin()->create();
    }

    /**
     * @param  list<int>  $ingredientIds
     */
    private function markCompatible(array $ingredientIds): void
    {
        Auth::login($this->admin);

        foreach ($ingredientIds as $ingredientId) {
            IngredientDietaryProfile::query()->updateOrCreate(['ingredient_id' => $ingredientId], [
                'composition_status' => IngredientCompositionStatus::Complete,
                'origin_classification' => IngredientOriginClassification::NotAnimalDerived,
                'evidence' => ['Reviewed ingredient documentation'],
                'reviewed_by' => $this->admin->getKey(),
                'reviewed_at' => now(),
            ]);
            IngredientDietaryAssessment::query()->updateOrCreate(['ingredient_id' => $ingredientId, 'tag_id' => $this->tag->getKey()], [
                'outcome' => DietaryCompatibility::Compatible,
                'verification_method' => 'product_label_review',
                'evidence' => ['Reviewed ingredient documentation'],
                'reviewed_by' => $this->admin->getKey(),
                'reviewed_at' => now(),
                'rule_version' => (int) config('dietary.claims.vegetarian.rule_version', 1),
            ]);
        }

        Auth::logout();
    }

    /**
     * @return list<int>
     */
    private function ingredientIds(Recipe $recipe): array
    {
        return $recipe->recipeIngredients()->pluck('ingredient_id')->map(fn ($id): int => (int) $id)->all();
    }

    private function approve(DietaryClaim $claim, ?User $reviewer = null): DietaryClaim
    {
        return $this->claims()->approve($reviewer ?? $this->reviewer(), $claim, DietaryVerificationMethod::EditorialIngredientReview, [], 'Checked ingredient list.');
    }

    private function suggest(?RecipeRevision $revision = null, ?Recipe $recipe = null): DietaryClaim
    {
        return $this->claims()->suggest($this->author, ($recipe ?? $this->recipe)->refresh(), $this->tag, DietaryVerificationMethod::EditorialIngredientReview, [], null, $revision);
    }

    private function approvedOnCurrent(): DietaryClaim
    {
        $this->markCompatible($this->ingredientIds($this->recipe));

        return $this->approve($this->suggest());
    }

    private function instructionChange(string $text = 'Simmer gently for twenty minutes.'): callable
    {
        return function (array $payload) use ($text): array {
            $payload['steps'][0]['translations']['en']['instruction'] = $text;
            $payload['steps'][0]['translations']['el']['instruction'] = 'Σιγοβράστε '.$text;

            return $payload;
        };
    }

    private function inReview(?callable $change = null): RecipeRevision
    {
        $draft = $this->revisions()->createDraft($this->author, $this->recipe);
        $draft = $this->revisions()->saveDraft($this->author, $draft, ($change ?? $this->instructionChange())($draft->payload));

        return $this->revisions()->submit($this->author, $draft);
    }

    private function lastAudit(DietaryClaim $claim): DietaryClaimAudit
    {
        return DietaryClaimAudit::query()->where('dietary_claim_id', $claim->id)->orderByDesc('id')->firstOrFail();
    }

    private function publicClaimTagCodes(): array
    {
        return $this->visibility()->publicClaims($this->recipe->fresh())->map(fn (DietaryClaim $claim): string => $claim->tag->code)->all();
    }

    public function test_claim_on_a_live_recipe_binds_to_the_current_revision_and_is_public_once_approved(): void
    {
        $claim = $this->approvedOnCurrent();

        $this->assertSame($this->recipe->current_revision_id, $claim->recipe_revision_id);
        $this->assertSame(['vegetarian'], $this->publicClaimTagCodes());
        $this->assertSame([$this->recipe->id], $this->visibility()->recipeIdsWithPublicClaim('vegetarian'));
        $this->assertSame($claim->recipe_revision_id, $this->lastAudit($claim)->recipe_revision_id);
    }

    public function test_claims_approved_for_an_in_review_revision_stay_hidden_until_it_is_published(): void
    {
        $this->markCompatible($this->ingredientIds($this->recipe));
        $review = $this->inReview();
        $claim = $this->approve($this->suggest($review));

        $this->assertSame($review->id, $claim->recipe_revision_id);
        $this->assertSame([], $this->publicClaimTagCodes());
        $this->assertSame([], $this->visibility()->publicClaimIndex());

        $this->revisions()->approve($this->reviewer(), $review);

        $this->assertSame($review->id, $this->recipe->fresh()->current_revision_id);
        $this->assertSame(DietaryClaimStatus::Approved, $claim->fresh()->status);
        $this->assertSame(['vegetarian'], $this->publicClaimTagCodes());
    }

    public function test_approvals_never_transfer_to_a_newer_revision(): void
    {
        $old = $this->approvedOnCurrent();
        $review = $this->inReview();

        $this->assertSame(0, DietaryClaim::query()->where('recipe_revision_id', $review->id)->count());

        $this->revisions()->approve($this->reviewer(), $review);

        $this->assertSame([], $this->publicClaimTagCodes());
        $this->assertSame([], $this->visibility()->publicClaimIndex());
        $this->assertSame(0, DietaryClaim::query()->where('recipe_revision_id', $review->id)->count());
        $this->assertSame(DietaryClaimStatus::Approved, $old->fresh()->status, 'The historical decision is preserved.');
        $this->assertSame(RecipeRevisionStatus::Superseded, $old->revision->fresh()->status);
        $this->assertFalse($this->visibility()->isCurrent($old->fresh()));
    }

    public function test_rollback_creates_a_revision_without_claims_and_hides_old_approvals(): void
    {
        $first = $this->approvedOnCurrent();
        $this->revisions()->approve($this->reviewer(), $this->inReview());

        $rollback = $this->revisions()->rollback($this->admin, $first->revision->fresh(), 'Restoring the first version.');

        $this->assertNotSame($first->recipe_revision_id, $rollback->id);
        $this->assertSame($rollback->id, $this->recipe->fresh()->current_revision_id);
        $this->assertSame(0, DietaryClaim::query()->where('recipe_revision_id', $rollback->id)->count());
        $this->assertSame([], $this->publicClaimTagCodes());
        $this->assertSame(DietaryClaimStatus::Approved, $first->fresh()->status);
    }

    public function test_drafts_and_unbound_proposals_cannot_be_approved(): void
    {
        $this->markCompatible($this->ingredientIds($this->recipe));
        $draft = $this->revisions()->createDraft($this->author, $this->recipe);
        $onDraft = $this->suggest($draft);

        try {
            $this->approve($onDraft);
            $this->fail('A draft revision must not be approvable.');
        } catch (ValidationException) {
            $this->assertSame(DietaryClaimStatus::Suggested, $onDraft->fresh()->status);
        }

        $unbound = Recipe::factory()->complete()->create(['author_id' => $this->author->id]);
        $proposal = $this->suggest(null, $unbound);
        $this->assertNull($proposal->recipe_revision_id);

        $this->expectException(ValidationException::class);
        $this->approve($proposal);
    }

    public function test_database_rejects_an_approved_claim_without_a_revision(): void
    {
        $claim = $this->approvedOnCurrent();

        $this->expectException(QueryException::class);
        DB::table('recipe_dietary_claims')->where('id', $claim->id)->update(['recipe_revision_id' => null]);
    }

    public function test_never_public_recipe_proposals_bind_on_first_publication_and_can_then_be_approved(): void
    {
        $recipe = Recipe::factory()->complete()->create(['author_id' => $this->author->id]);
        $this->markCompatible($this->ingredientIds($recipe));
        $proposal = $this->suggest(null, $recipe);

        $this->assertNull($proposal->recipe_revision_id);
        $this->assertTrue($this->visibility()->publicClaims($recipe)->isEmpty());

        $this->publish($recipe);
        $proposal->refresh();

        $this->assertSame($recipe->fresh()->current_revision_id, $proposal->recipe_revision_id);
        $this->assertSame(DietaryClaimStatus::Suggested, $proposal->status);
        $this->assertSame('suggested', $this->lastAudit($proposal)->action->value);
        $this->assertSame($proposal->recipe_revision_id, $this->lastAudit($proposal)->recipe_revision_id);

        $this->approve($proposal);
        $this->assertCount(1, $this->visibility()->publicClaims($recipe->fresh()));
    }

    public function test_ingredient_metadata_changes_invalidate_published_and_in_review_claims_immediately(): void
    {
        $published = $this->approvedOnCurrent();
        $review = $this->inReview();
        $onReview = $this->approve($this->suggest($review));
        $ingredientId = $this->ingredientIds($this->recipe)[0];

        $this->actingAs($this->admin);
        IngredientDietaryAssessment::query()
            ->where('ingredient_id', $ingredientId)->where('tag_id', $this->tag->id)
            ->first()->update(['outcome' => DietaryCompatibility::Unknown]);

        $this->assertSame(DietaryClaimStatus::Invalidated, $published->fresh()->status);
        $this->assertSame(DietaryClaimStatus::Invalidated, $onReview->fresh()->status);
        $this->assertSame([], $this->publicClaimTagCodes());
        $this->assertSame('invalidated', $this->lastAudit($published)->action->value);
        $this->assertSame($published->recipe_revision_id, $this->lastAudit($published)->recipe_revision_id);
    }

    public function test_compound_ingredient_composition_changes_invalidate_an_in_review_claim(): void
    {
        $this->markCompatible($this->ingredientIds($this->recipe));
        $review = $this->inReview();
        $claim = $this->approve($this->suggest($review));

        $this->actingAs($this->admin);
        IngredientComponent::query()->create([
            'ingredient_id' => $this->ingredientIds($this->recipe)[0],
            'unresolved_component' => 'natural flavouring of unknown origin',
        ]);

        $this->assertSame(DietaryClaimStatus::Invalidated, $claim->fresh()->status);
    }

    public function test_raw_database_changes_are_hidden_at_read_time_even_without_observers(): void
    {
        $claim = $this->approvedOnCurrent();
        $this->assertSame(['vegetarian'], $this->publicClaimTagCodes());

        DB::table('ingredient_dietary_assessments')->update(['outcome' => DietaryCompatibility::Incompatible->value]);

        $this->assertSame(DietaryClaimStatus::Approved, $claim->fresh()->status);
        $this->assertSame([], $this->publicClaimTagCodes());
        $this->assertSame([], $this->visibility()->publicClaimIndex());

        $this->assertSame(1, $this->claims()->revalidateAll());
        $this->assertSame(DietaryClaimStatus::Invalidated, $claim->fresh()->status);
    }

    public function test_changed_rule_version_hides_claims_and_revalidation_persists_it(): void
    {
        $claim = $this->approvedOnCurrent();

        config(['dietary.claims.vegetarian.rule_version' => 99]);

        $this->assertSame([], $this->publicClaimTagCodes());
        $this->assertSame(1, $this->claims()->revalidateAll());
        $this->assertSame(DietaryClaimStatus::Invalidated, $claim->fresh()->status);
        $this->assertStringContainsString('rule version', $this->lastAudit($claim)->notes);
    }

    public function test_changed_instructions_in_the_live_recipe_invalidate_the_published_claim(): void
    {
        $claim = $this->approvedOnCurrent();

        $this->recipe->steps()->first()->fill(['en' => ['instruction' => 'Add a splash of fish sauce.']])->save();

        $this->assertSame(DietaryClaimStatus::Invalidated, $claim->fresh()->status);
        $this->assertSame([], $this->publicClaimTagCodes());
    }

    public function test_in_review_revision_claim_is_checked_against_its_own_frozen_content(): void
    {
        $this->markCompatible($this->ingredientIds($this->recipe));
        $review = $this->inReview();
        $claim = $this->suggest($review);

        $liveHash = app(RecipeDietaryFingerprint::class)->for($this->recipe, $this->tag);

        $this->assertNotSame($liveHash, $claim->revision_hash, 'Changed instructions must produce a different fingerprint.');
        $this->assertSame($claim->revision_hash, app(RecipeDietaryFingerprint::class)->forRevision($review, $this->tag));
    }

    public function test_payload_and_live_fingerprints_agree_for_identical_content(): void
    {
        $fingerprint = app(RecipeDietaryFingerprint::class);
        $revision = $this->recipe->currentRevision;

        $this->assertSame($fingerprint->for($this->recipe, $this->tag), $fingerprint->forRevision($revision, $this->tag));
    }

    public function test_suggester_cannot_approve_and_contributors_and_editors_cannot_approve(): void
    {
        $this->markCompatible($this->ingredientIds($this->recipe));
        $claim = $this->suggest();

        foreach ([$this->author, User::factory()->editor()->create(), User::factory()->contributor()->create()] as $user) {
            try {
                $this->claims()->approve($user, $claim, DietaryVerificationMethod::EditorialIngredientReview);
                $this->fail('User must not approve.');
            } catch (AuthorizationException) {
                $this->assertSame(DietaryClaimStatus::Suggested, $claim->fresh()->status);
            }
        }

        $this->claims()->suggest($this->admin, $this->recipe, $this->tag, DietaryVerificationMethod::EditorialIngredientReview);
        $claim = DietaryClaim::query()->where('recipe_revision_id', $this->recipe->current_revision_id)->first();
        $this->assertSame($this->admin->id, (int) $claim->suggested_by);

        $this->expectException(AuthorizationException::class);
        $this->claims()->approve($this->admin, $claim, DietaryVerificationMethod::EditorialIngredientReview);
    }

    public function test_a_reviewer_who_submitted_the_revision_cannot_approve_its_claims(): void
    {
        $this->markCompatible($this->ingredientIds($this->recipe));
        $submitter = $this->reviewer();
        $draft = $this->revisions()->createDraft($submitter, $this->recipe);
        $draft = $this->revisions()->saveDraft($submitter, $draft, $this->instructionChange()($draft->payload));
        $review = $this->revisions()->submit($submitter, $draft);
        $claim = $this->suggest($review);

        try {
            $this->approve($claim, $submitter);
            $this->fail('An involved reviewer must not approve.');
        } catch (ValidationException) {
            $this->assertSame(DietaryClaimStatus::Suggested, $claim->fresh()->status);
        }

        $this->assertSame(DietaryClaimStatus::Approved, $this->approve($claim)->status);
    }

    public function test_claims_for_a_foreign_or_closed_revision_are_refused(): void
    {
        $other = Recipe::factory()->complete()->create(['author_id' => $this->author->id]);
        $this->publish($other);

        try {
            $this->suggest($other->refresh()->currentRevision, $this->recipe);
            $this->fail('A revision of another recipe must be refused.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $old = $this->recipe->currentRevision;
        $this->revisions()->approve($this->reviewer(), $this->inReview());

        $this->expectException(ValidationException::class);
        $this->suggest($old->fresh());
    }

    public function test_public_search_only_lists_recipes_with_a_current_revision_bound_claim(): void
    {
        $this->approvedOnCurrent();

        Livewire::test(RecipeIndex::class, ['locale' => 'en'])
            ->set('tag', 'vegetarian')
            ->assertViewHas('recipes', fn ($recipes) => $recipes->count() === 1);

        $this->revisions()->approve($this->reviewer(), $this->inReview());

        Livewire::test(RecipeIndex::class, ['locale' => 'en'])
            ->set('tag', 'vegetarian')
            ->assertViewHas('recipes', fn ($recipes) => $recipes->isEmpty());
    }

    public function test_claim_metadata_is_available_in_english_and_greek(): void
    {
        $this->approvedOnCurrent();

        $tag = $this->visibility()->publicClaims($this->recipe->fresh())->first()->tag;

        $this->assertSame('Vegetarian Label', $tag->translate('en')->name);
        $this->assertSame('Χορτοφαγικό Label', $tag->translate('el')->name);
    }

    public function test_public_gating_flags_still_hide_claims_when_release_is_disabled(): void
    {
        $this->approvedOnCurrent();
        config(['dietary.public_release_enabled' => false]);

        $this->assertSame([], $this->publicClaimTagCodes());
        $this->assertSame([], $this->visibility()->publicClaimIndex());
    }

    public function test_the_migration_withdraws_ambiguous_approved_claims_and_keeps_the_constraint(): void
    {
        $constraint = DB::selectOne("SELECT 1 AS present FROM pg_constraint WHERE conname = 'recipe_dietary_claims_approved_needs_revision'");

        $this->assertNotNull($constraint);
    }
}
