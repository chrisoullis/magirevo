<?php

namespace Tests\Feature;

use App\Enums\DietaryClaimStatus;
use App\Enums\DietaryCompatibility;
use App\Enums\DietaryVerificationMethod;
use App\Models\DietaryClaimAudit;
use App\Models\Ingredient;
use App\Models\IngredientComponent;
use App\Models\IngredientDietaryAssessment;
use App\Models\IngredientDietaryProfile;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeRevision;
use App\Models\RecipeShare;
use App\Models\Tag;
use App\Models\User;
use App\Notifications\RecipeComponentUnavailable;
use App\Services\DietaryClaimService;
use App\Services\IngredientDietaryResolver;
use App\Services\RecipeAccessService;
use App\Services\RecipeComponentDietaryTraversal;
use App\Services\RecipeComponentInvalidationService;
use App\Services\RecipeComponentResolver;
use App\Services\RecipeContentGuard;
use App\Services\RecipeDietaryFingerprint;
use App\Services\RecipePublishingService;
use App\Services\RecipeRevisionService;
use App\Support\AppPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class NestedRecipeDietaryIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function source(): RecipeRevision
    {
        $this->actingAs(User::factory()->admin()->create());
        $recipe = Recipe::factory()->complete()->create();
        app(RecipePublishingService::class)->publish($recipe);

        return $recipe->fresh()->currentRevision;
    }

    private function parent(RecipeRevision $source): Recipe
    {
        $parent = Recipe::factory()->published()->create();
        RecipeIngredient::factory()->for($parent)->component($source)->create([
            'en' => ['preparation_note' => 'Chilled'], 'el' => ['preparation_note' => 'Κρύο'],
        ]);
        app(RecipeRevisionService::class)->syncPublishedFromLive($parent->id);

        return $parent->fresh();
    }

    private function reviewed(int $id, Tag $tag): void
    {
        $reviewer = auth()->user();
        IngredientDietaryProfile::updateOrCreate(['ingredient_id' => $id], [
            'composition_status' => 'complete', 'origin_classification' => 'not_animal_derived',
            'evidence' => ['Reviewed composition'], 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(),
        ]);
        IngredientDietaryAssessment::updateOrCreate(['ingredient_id' => $id, 'tag_id' => $tag->id], [
            'outcome' => 'compatible', 'verification_method' => 'product_label_review',
            'evidence' => ['Reviewed label'], 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(),
            'rule_version' => (int) config("dietary.claims.{$tag->code}.rule_version", 1),
        ]);
    }

    public function test_nested_raw_ingredients_and_compound_metadata_are_evaluated_without_component_claims(): void
    {
        $source = $this->source();
        $parent = $this->parent($source);
        $tag = Tag::factory()->dietary()->create(['code' => 'vegetarian']);
        $ingredientId = $source->payload['ingredients'][0]['ingredient_id'];
        $this->reviewed($ingredientId, $tag);
        $resolver = app(IngredientDietaryResolver::class);
        $this->assertSame(DietaryCompatibility::Compatible, $resolver->resolve($parent, $tag));
        $unknown = Ingredient::factory()->create();
        IngredientComponent::create(['ingredient_id' => $ingredientId, 'component_ingredient_id' => $unknown->id, 'evidence' => ['Compound label']]);
        $this->assertSame(DietaryCompatibility::Unknown, $resolver->resolve($parent, $tag));
        $this->assertDatabaseCount('recipe_dietary_claims', 0);
    }

    public function test_prepared_mapping_never_replaces_raw_dietary_evidence_and_live_matches_frozen_hash(): void
    {
        $source = $this->source();
        $parent = $this->parent($source);
        $line = $parent->recipeIngredients()->firstOrFail();
        $line->update(['shopping_mode' => 'prepared', 'prepared_ingredient_id' => Ingredient::factory()->create()->id, 'prepared_quantity_per_batch' => '1.0000']);
        $revision = app(RecipeRevisionService::class)->syncPublishedFromLive($parent->id);
        $inspection = app(RecipeComponentDietaryTraversal::class)->inspect($revision);
        $this->assertSame([$source->payload['ingredients'][0]['ingredient_id']], $inspection['ids']);
        $fingerprint = app(RecipeDietaryFingerprint::class);
        $this->assertSame($fingerprint->for($parent), $fingerprint->forRevision($revision));
    }

    public function test_new_source_publication_does_not_change_parent_pinned_fingerprint(): void
    {
        $source = $this->source();
        $parent = $this->parent($source);
        $fingerprint = app(RecipeDietaryFingerprint::class);
        $before = $fingerprint->for($parent);
        $source->recipe->recipeIngredients()->firstOrFail()->update(['quantity' => '9.0000']);
        app(RecipeRevisionService::class)->syncPublishedFromLive($source->recipe_id);
        $this->assertSame($before, $fingerprint->for($parent));
        $this->assertSame($source->id, $parent->recipeIngredients()->firstOrFail()->component_recipe_revision_id);
    }

    public function test_unpublished_source_blocks_transitive_public_access_search_and_dietary_resolution(): void
    {
        $source = $this->source();
        $parent = $this->parent($source);
        $grandparent = $this->parent($parent->currentRevision);
        $this->assertTrue($grandparent->isPubliclyAccessible());
        $this->assertEqualsCanonicalizing([$parent->id, $grandparent->id], app(RecipeComponentInvalidationService::class)->dependentRecipeIds($source->recipe_id));
        app(RecipePublishingService::class)->unpublish($source->recipe);
        $tag = Tag::factory()->dietary()->create(['code' => 'vegetarian']);
        $this->assertFalse($grandparent->fresh()->isPubliclyAccessible());
        $this->assertFalse(app(RecipeAccessService::class)->canView(null, $grandparent->fresh()));
        $this->assertFalse(Recipe::published()->whereKey($grandparent->id)->exists());
        $this->assertSame(DietaryCompatibility::Unknown, app(IngredientDietaryResolver::class)->resolve($grandparent->fresh(), $tag));
    }

    public function test_public_scope_uses_current_dependency_when_monitor_mode_live_component_was_removed(): void
    {
        $source = $this->source();
        $parent = $this->parent($source);
        app(RecipeContentGuard::class)->setMode('monitor');
        $parent->recipeIngredients()->delete();
        $this->assertTrue($parent->fresh()->isPubliclyAccessible());
        app(RecipePublishingService::class)->unpublish($source->recipe);
        $this->assertFalse($parent->fresh()->isPubliclyAccessible());
        $this->assertContains($parent->id, app(RecipeComponentInvalidationService::class)->publiclyBlockedRecipeIds());
        $this->assertFalse(Recipe::published()->whereKey($parent->id)->exists());
    }

    public function test_revoking_a_shared_source_fails_closed_for_a_private_parent_without_exposing_content(): void
    {
        $source = $this->source();
        $parent = $this->parent($source);
        $viewer = User::factory()->contributor()->create();
        $viewer->givePermissionTo(AppPermissions::RECIPES_VIEW_SHARED);
        $parent->update(['visibility' => 'private', 'author_id' => $viewer->id]);
        $source->recipe->update(['visibility' => 'shared']);
        $share = RecipeShare::create([
            'recipe_id' => $source->recipe_id, 'user_id' => $viewer->id,
            'shared_by' => $source->recipe->author_id, 'permission' => 'view',
        ]);
        $traversal = app(RecipeComponentDietaryTraversal::class);
        $this->assertFalse($traversal->inspect($parent->fresh())['unresolved']);
        $before = app(RecipeDietaryFingerprint::class)->for($parent);
        $share->update(['revoked_at' => now()]);
        $this->assertTrue($traversal->inspect($parent->fresh())['unresolved']);
        $this->assertFalse(app(RecipeAccessService::class)->canView($viewer, $parent->fresh()));
        $this->assertNotSame($before, app(RecipeDietaryFingerprint::class)->for($parent));
        $tag = Tag::factory()->dietary()->create(['code' => 'vegetarian']);
        $warnings = app(IngredientDietaryResolver::class)->warnings($parent->fresh(), $tag);
        $this->assertSame(['A recipe component is unavailable or unresolved; dietary verification is unknown.'], $warnings);
    }

    public function test_component_invalidation_waits_for_commit_and_notifications_contain_only_parent_reference(): void
    {
        $source = $this->source();
        $parent = $this->parent($source);
        $callbacks = [];
        DB::partialMock()->shouldReceive('afterCommit')->once()
            ->andReturnUsing(function (callable $callback) use (&$callbacks): void {
                $callbacks[] = $callback;
            });
        Notification::fake();
        app(RecipeComponentInvalidationService::class)->schedule($source->recipe_id, true);
        $this->assertCount(1, $callbacks);
        Notification::assertNothingSent();
        $notification = new RecipeComponentUnavailable($parent->id);
        $message = $notification->toMail($parent->author)->toArray();
        $this->assertStringContainsString('Recipe reference: '.$parent->id, json_encode($message));
        $this->assertStringNotContainsString($source->payload['translations']['en']['title'], json_encode($message));
    }

    public function test_recipe_identity_cycle_is_unknown_even_when_the_referenced_revision_is_valid(): void
    {
        $source = $this->source();
        $subject = new RecipeRevision([
            'recipe_id' => $source->recipe_id,
            'payload' => ['ingredients' => [[
                'line_type' => 'recipe', 'component_recipe_id' => $source->recipe_id,
                'component_recipe_revision_id' => $source->id, 'quantity' => '1.0000',
            ]]],
        ]);
        $subject->setRelation('recipe', $source->recipe);
        $this->assertTrue(app(RecipeComponentDietaryTraversal::class)->inspect($subject)['unresolved']);
    }

    public function test_retained_private_snapshot_does_not_become_readable_when_source_is_later_public(): void
    {
        $owner = User::factory()->admin()->create();
        $this->actingAs($owner);
        $source = Recipe::factory()->complete()->create(['author_id' => $owner->id]);
        $retained = app(RecipeRevisionService::class)->captureComponentSnapshot($owner, $source);
        app(RecipePublishingService::class)->publish($source);
        $parent = Recipe::factory()->create(['author_id' => User::factory()->contributor()->create()->id]);
        $subject = new RecipeRevision([
            'recipe_id' => $parent->id,
            'payload' => ['ingredients' => [[
                'line_type' => 'recipe', 'component_recipe_id' => $source->id,
                'component_recipe_revision_id' => $retained->id, 'quantity' => '1.0000',
            ]]],
        ]);
        $subject->setRelation('recipe', $parent);
        $traversal = app(RecipeComponentDietaryTraversal::class);
        $this->assertTrue($traversal->inspect($subject)['unresolved']);
        $parent->setRelation('author', $owner);
        $this->assertFalse($traversal->inspect($subject)['unresolved']);
    }

    public function test_corrupt_pinned_content_hash_fails_closed_without_exposing_ingredients(): void
    {
        $source = $this->source();
        $parent = $this->parent($source);
        RecipeRevision::retrieved(function (RecipeRevision $revision) use ($source): void {
            if ($revision->id === $source->id) {
                $revision->content_hash = str_repeat('0', 64);
            }
        });
        $result = app(RecipeComponentDietaryTraversal::class)->inspect($parent);
        $this->assertTrue($result['unresolved']);
        $this->assertSame([], $result['ids']);
        $this->assertFalse($parent->isPubliclyAccessible());
    }

    public function test_component_budget_is_global_across_repeated_sibling_branches(): void
    {
        $source = $this->source();
        $branch = $this->parent($source);
        $root = Recipe::factory()->published()->create();
        $lines = [];
        for ($index = 0; $index <= intdiv(RecipeComponentResolver::MAX_COMPONENT_OCCURRENCES, 2); $index++) {
            $lines[] = [
                'line_type' => 'recipe', 'component_recipe_id' => $branch->id,
                'component_recipe_revision_id' => $branch->current_revision_id,
                'quantity' => '1', 'line_uuid' => (string) Str::uuid(),
            ];
        }
        $subject = new RecipeRevision(['recipe_id' => $root->id, 'payload' => ['ingredients' => $lines]]);
        $subject->setRelation('recipe', $root);
        foreach ([true, false] as $content) {
            $result = app(RecipeComponentDietaryTraversal::class)->inspect($subject, $content);
            $this->assertTrue($result['unresolved']);
            $this->assertSame([], $result['ids']);
        }
    }

    public function test_expanded_leaf_budget_fails_closed_for_content_and_access_only_walks(): void
    {
        $source = $this->source();
        $subject = new RecipeRevision([
            'recipe_id' => $source->recipe_id,
            'payload' => ['ingredients' => array_fill(0, RecipeComponentResolver::MAX_EXPANDED_LINES + 1, $source->payload['ingredients'][0])],
        ]);
        $subject->setRelation('recipe', $source->recipe);
        foreach ([true, false] as $content) {
            $result = app(RecipeComponentDietaryTraversal::class)->inspect($subject, $content);
            $this->assertTrue($result['unresolved']);
            $this->assertSame([], $result['ids']);
        }
    }

    public function test_missing_pinned_leaf_is_unknown_in_both_walk_modes(): void
    {
        $source = $this->source();
        $subject = new RecipeRevision([
            'recipe_id' => $source->recipe_id,
            'payload' => ['ingredients' => [['ingredient_id' => PHP_INT_MAX]]],
        ]);
        $subject->setRelation('recipe', $source->recipe);
        foreach ([true, false] as $content) {
            $result = app(RecipeComponentDietaryTraversal::class)->inspect($subject, $content);
            $this->assertTrue($result['unresolved']);
            $this->assertSame([], $result['ids']);
        }
    }

    public function test_nested_metadata_change_invalidates_approval_once_and_preserves_audit_history(): void
    {
        $source = $this->source();
        $parent = $this->parent($source);
        $tag = Tag::factory()->dietary()->create(['code' => 'vegetarian']);
        $ingredientId = $source->payload['ingredients'][0]['ingredient_id'];
        $this->reviewed($ingredientId, $tag);
        $service = app(DietaryClaimService::class);
        $actor = auth()->user();
        $claim = $service->suggest($actor, $parent, $tag, DietaryVerificationMethod::EditorialIngredientReview);
        $claim = $service->approve(User::factory()->admin()->create(), $claim, DietaryVerificationMethod::EditorialIngredientReview, [], 'Reviewed raw nested ingredients.');
        $this->assertSame(DietaryClaimStatus::Approved, $claim->fresh()->status);
        $count = DietaryClaimAudit::where('dietary_claim_id', $claim->id)->count();
        IngredientDietaryAssessment::where('ingredient_id', $ingredientId)->firstOrFail()->update(['outcome' => 'unknown']);
        // Observer callbacks can already invalidate on the inner transaction's test commit.
        $service->revalidate($parent);
        $this->assertSame(DietaryClaimStatus::Invalidated, $claim->fresh()->status);
        $this->assertSame($count + 1, DietaryClaimAudit::where('dietary_claim_id', $claim->id)->count());
        $this->assertSame(0, $service->revalidate($parent));
    }
}
