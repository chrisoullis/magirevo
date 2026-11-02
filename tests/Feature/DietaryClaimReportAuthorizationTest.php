<?php

namespace Tests\Feature;

use App\Enums\DietaryCompatibility;
use App\Enums\DietaryVerificationMethod;
use App\Enums\IngredientCompositionStatus;
use App\Enums\IngredientOriginClassification;
use App\Models\DietaryClaim;
use App\Models\IngredientDietaryAssessment;
use App\Models\IngredientDietaryProfile;
use App\Models\Recipe;
use App\Models\Tag;
use App\Models\User;
use App\Services\DietaryClaimService;
use App\Services\RecipePublishingService;
use App\Services\RecipeRevisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Public report endpoints must never confirm or accept reports about claims the public cannot see.
 */
class DietaryClaimReportAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    private User $admin;

    private Recipe $recipe;

    private DietaryClaim $claim;

    protected function setUp(): void
    {
        parent::setUp();

        config(['dietary.public_release_enabled' => true, 'dietary.public_claim_codes' => ['vegetarian']]);
        $this->author = User::factory()->contributor()->create();
        $this->admin = User::factory()->admin()->create();
        $tag = Tag::factory()->dietary('diet_style', true)->create(['code' => 'vegetarian']);

        $this->recipe = Recipe::factory()->complete()->create(['author_id' => $this->author->id]);
        $this->actingAs($this->admin);
        app(RecipePublishingService::class)->publish($this->recipe);
        $this->recipe->refresh();

        foreach ($this->recipe->recipeIngredients()->pluck('ingredient_id') as $ingredientId) {
            IngredientDietaryProfile::query()->create([
                'ingredient_id' => $ingredientId,
                'composition_status' => IngredientCompositionStatus::Complete,
                'origin_classification' => IngredientOriginClassification::NotAnimalDerived,
                'evidence' => ['Reviewed'],
                'reviewed_by' => $this->admin->id,
                'reviewed_at' => now(),
            ]);
            IngredientDietaryAssessment::query()->create([
                'ingredient_id' => $ingredientId,
                'tag_id' => $tag->id,
                'outcome' => DietaryCompatibility::Compatible,
                'verification_method' => 'product_label_review',
                'evidence' => ['Reviewed'],
                'reviewed_by' => $this->admin->id,
                'reviewed_at' => now(),
                'rule_version' => (int) config('dietary.claims.vegetarian.rule_version', 1),
            ]);
        }

        $service = app(DietaryClaimService::class);
        $claim = $service->suggest($this->author, $this->recipe, $tag, DietaryVerificationMethod::EditorialIngredientReview);
        $this->claim = $service->approve(User::factory()->admin()->create(), $claim, DietaryVerificationMethod::EditorialIngredientReview, [], 'Checked.');
    }

    private function report(Recipe $recipe, DietaryClaim $claim, ?User $user = null)
    {
        $recipe->refresh();

        return $this->actingAs($user ?? User::factory()->contributor()->create())
            ->post(route('recipes.dietary-claims.report', ['locale' => 'en', 'slug' => $recipe->getTranslation('en', false)->slug, 'claim' => $claim->getKey()]), ['notes' => 'This looks wrong to me.']);
    }

    public function test_a_current_public_claim_can_be_reported(): void
    {
        $this->report($this->recipe, $this->claim)->assertRedirect();

        $this->assertDatabaseHas('dietary_claim_audits', ['dietary_claim_id' => $this->claim->id, 'action' => 'flagged']);
    }

    public function test_a_claim_on_a_superseded_revision_cannot_be_reported_or_retrieved(): void
    {
        $revisions = app(RecipeRevisionService::class);
        $draft = $revisions->createDraft($this->author, $this->recipe);
        $payload = $draft->payload;
        $payload['translations']['en']['summary'] = 'A new summary';
        $revision = $revisions->submit($this->author, $revisions->saveDraft($this->author, $draft, $payload));
        $revisions->approve(User::factory()->admin()->create(), $revision);

        $reporter = User::factory()->contributor()->create();

        $this->report($this->recipe, $this->claim, $reporter)->assertNotFound();
        $this->assertFalse(Gate::forUser($reporter)->allows('report', $this->claim->fresh()));
        $this->assertSame(0, $this->claim->audits()->where('action', 'flagged')->count());
    }

    public function test_a_claim_of_a_private_recipe_cannot_be_reported(): void
    {
        $this->recipe->update(['visibility' => 'private']);

        $this->report($this->recipe, $this->claim)->assertNotFound();
        $this->assertSame(0, $this->claim->audits()->where('action', 'flagged')->count());
    }

    public function test_a_claim_cannot_be_reported_through_another_public_recipe_slug(): void
    {
        $other = Recipe::factory()->complete()->create();
        $this->actingAs($this->admin);
        app(RecipePublishingService::class)->publish($other);

        $this->report($other, $this->claim)->assertNotFound();
    }

    public function test_a_claim_withdrawn_from_public_view_cannot_be_reported(): void
    {
        $this->claim->update(['status' => 'suspended']);

        $this->report($this->recipe, $this->claim)->assertNotFound();
    }

    public function test_guests_and_unverified_users_cannot_report(): void
    {
        auth()->logout();

        $this->post(route('recipes.dietary-claims.report', ['locale' => 'en', 'slug' => $this->recipe->getTranslation('en', false)->slug, 'claim' => $this->claim->id]), ['notes' => 'Problem here.'])
            ->assertRedirect();

        $this->report($this->recipe, $this->claim, User::factory()->unverified()->create())->assertRedirect();
        $this->assertSame(0, $this->claim->audits()->where('action', 'flagged')->count());
    }
}
