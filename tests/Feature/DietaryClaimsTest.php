<?php

namespace Tests\Feature;

use App\Enums\DietaryClaimStatus;
use App\Enums\DietaryCompatibility;
use App\Enums\DietaryVerificationMethod;
use App\Enums\IngredientCompositionStatus;
use App\Enums\IngredientOriginClassification;
use App\Enums\TagClassification;
use App\Livewire\RecipeIndex;
use App\Livewire\WhatCanICook;
use App\Models\DietaryClaim;
use App\Models\DietaryClaimAudit;
use App\Models\Ingredient;
use App\Models\IngredientAllergenAssessment;
use App\Models\IngredientComponent;
use App\Models\IngredientDietaryAssessment;
use App\Models\IngredientDietaryProfile;
use App\Models\IngredientSubstitution;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\Tag;
use App\Models\User;
use App\Models\UserRecipeIngredientChange;
use App\Services\DietaryClaimService;
use App\Services\DietaryClaimVisibility;
use App\Services\IngredientDietaryResolver;
use App\Services\MealPlanService;
use App\Services\RecipeDietaryFingerprint;
use App\Services\RecipeRevisionService;
use App\Support\AppPermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

class DietaryClaimsTest extends TestCase
{
    use RefreshDatabase;

    private function claimTag(string $code = 'vegetarian', string $classification = 'diet_style', bool $enabled = true): Tag
    {
        $tag = Tag::factory()->dietary($classification, $enabled)->create(['code' => $code]);
        $tag->translations()->createMany([
            ['locale' => 'en', 'name' => ucfirst($code).' Label', 'slug' => $code.'-en'],
            ['locale' => 'el', 'name' => 'Ετικέτα '.$code, 'slug' => $code.'-el'],
        ]);

        return $tag;
    }

    private function editorialTag(): Tag
    {
        $tag = Tag::factory()->create(['code' => 'quick']);
        $tag->translations()->createMany([
            ['locale' => 'en', 'name' => 'Quick Label', 'slug' => 'quick-en'],
            ['locale' => 'el', 'name' => 'Γρήγορο', 'slug' => 'quick-el'],
        ]);

        return $tag;
    }

    private function publicRecipe(?User $author = null, string $title = 'Lentil stew'): Recipe
    {
        $recipe = Recipe::factory()->published()->create(['author_id' => $author?->getKey() ?? User::factory()]);
        $recipe->translations()->where('locale', 'en')->update(['title' => $title, 'slug' => str($title)->slug().'-'.$recipe->getKey()]);
        RecipeIngredient::factory()->create(['recipe_id' => $recipe->getKey(), 'quantity' => '200.0000']);
        $step = $recipe->steps()->create(['step_number' => 1]);
        $step->fill(['en' => ['instruction' => 'Simmer the lentils.'], 'el' => ['instruction' => 'Σιγοβράστε τις φακές.']])->save();
        app(RecipeRevisionService::class)->syncPublishedFromLive($recipe->getKey());

        return $recipe->refresh();
    }

    private function approved(Recipe $recipe, Tag $tag): DietaryClaim
    {
        $contributor = User::factory()->contributor()->create();
        $reviewer = User::factory()->admin()->create();
        $recipe->update(['author_id' => $contributor->getKey()]);
        $this->markIngredientsCompatible($recipe, $tag, $reviewer);
        $service = app(DietaryClaimService::class);
        $claim = $service->suggest($contributor, $recipe->refresh(), $tag, DietaryVerificationMethod::EditorialIngredientReview);

        return $service->approve($reviewer, $claim, DietaryVerificationMethod::EditorialIngredientReview, [], 'Checked the ingredient list.');
    }

    private function markIngredientsCompatible(Recipe $recipe, Tag $tag, User $reviewer): void
    {
        Auth::login($reviewer);

        foreach ($recipe->recipeIngredients as $line) {
            IngredientDietaryProfile::query()->updateOrCreate(
                ['ingredient_id' => $line->ingredient_id],
                [
                    'composition_status' => IngredientCompositionStatus::Complete,
                    'origin_classification' => IngredientOriginClassification::NotAnimalDerived,
                    'evidence' => ['Reviewed ingredient documentation'],
                    'reviewed_by' => $reviewer->getKey(),
                    'reviewed_at' => now(),
                ],
            );
            IngredientDietaryAssessment::query()->updateOrCreate(
                ['ingredient_id' => $line->ingredient_id, 'tag_id' => $tag->getKey()],
                [
                    'outcome' => DietaryCompatibility::Compatible,
                    'verification_method' => 'product_label_review',
                    'evidence' => ['Reviewed ingredient documentation'],
                    'reviewed_by' => $reviewer->getKey(),
                    'reviewed_at' => now(),
                    'rule_version' => (int) config("dietary.claims.{$tag->code}.rule_version", 1),
                ],
            );
        }

        Auth::logout();
    }

    private function enablePublicClaims(string ...$codes): void
    {
        config([
            'dietary.public_release_enabled' => true,
            'dietary.public_claim_codes' => $codes,
        ]);
    }

    private function url(Recipe $recipe, string $locale = 'en'): string
    {
        return "/{$locale}/recipes/".$recipe->getTranslation($locale, false)->slug;
    }

    public function test_unapproved_dietary_tags_stay_hidden_everywhere_but_editorial_tags_remain(): void
    {
        $vegetarian = $this->claimTag();
        $quick = $this->editorialTag();
        $recipe = $this->publicRecipe();
        $recipe->tags()->attach([$vegetarian->getKey(), $quick->getKey()]);

        Livewire::test(RecipeIndex::class, ['locale' => 'en'])
            ->assertViewHas('tags', fn ($tags) => $tags->pluck('code')->all() === ['quick'])
            ->set('tag', 'vegetarian')
            ->assertViewHas('recipes', fn ($recipes) => $recipes->isEmpty());

        $page = $this->get($this->url($recipe))->assertOk();
        $page->assertSee('Quick Label')->assertDontSee('Vegetarian Label')->assertDontSee(__('site.dietary_information'));

        $this->assertSame([], app(DietaryClaimVisibility::class)->publicClaimIndex());
    }

    public function test_unclassified_tags_are_never_public(): void
    {
        $tag = Tag::factory()->create(['code' => 'mystery', 'classification' => 'unclassified']);
        $tag->translations()->create(['locale' => 'en', 'name' => 'Mystery Label', 'slug' => 'mystery']);
        $recipe = $this->publicRecipe();
        $recipe->tags()->attach($tag);

        Livewire::test(RecipeIndex::class, ['locale' => 'en'])->assertViewHas('tags', fn ($tags) => $tags->pluck('code')->doesntContain('mystery'))->set('tag', 'mystery')->assertViewHas('recipes', fn ($recipes) => $recipes->isEmpty());
    }

    public function test_approved_current_claim_appears_in_page_filter_options_and_structured_data(): void
    {
        $this->enablePublicClaims('vegetarian');
        $tag = $this->claimTag();
        $recipe = $this->publicRecipe();
        $this->approved($recipe, $tag);

        Livewire::test(RecipeIndex::class, ['locale' => 'en'])
            ->assertViewHas('tags', fn ($tags) => $tags->pluck('code')->contains('vegetarian'))
            ->set('tag', 'vegetarian')
            ->assertViewHas('recipes', fn ($recipes) => $recipes->count() === 1)
            ->set('tag', '')->set('dietary', 'vegetarian')
            ->assertViewHas('recipes', fn ($recipes) => $recipes->modelKeys() === [$recipe->id])
            ->assertViewHas('recipeClaims', fn ($claims) => ($claims[$recipe->id] ?? []) === ['Vegetarian Label']);

        $this->get($this->url($recipe))->assertOk()
            ->assertSee(__('site.dietary_information'))
            ->assertSee('Vegetarian Label')
            ->assertSee('not a professional certification', false);
    }

    public function test_claims_are_translated_per_locale(): void
    {
        $this->enablePublicClaims('vegetarian');
        $tag = $this->claimTag();
        $recipe = $this->publicRecipe();
        $this->approved($recipe, $tag);

        $this->get($this->url($recipe, 'el'))->assertOk()->assertSee('Ετικέτα vegetarian');
        $this->get($this->url($recipe, 'en'))->assertOk()->assertDontSee('Ετικέτα vegetarian');
    }

    public function test_public_claims_need_the_release_flag_and_an_explicit_code_allowlist(): void
    {
        $tag = $this->claimTag();
        $recipe = $this->publicRecipe();
        $claim = $this->approved($recipe, $tag);

        $this->assertCount(0, app(DietaryClaimVisibility::class)->publicClaims($recipe));

        config(['dietary.public_release_enabled' => true, 'dietary.public_claim_codes' => ['vegan']]);
        $this->assertCount(0, app(DietaryClaimVisibility::class)->publicClaims($recipe));

        $this->enablePublicClaims('vegetarian');
        $this->assertSame(DietaryClaimStatus::Approved, $claim->fresh()->status);
        $this->assertCount(1, app(DietaryClaimVisibility::class)->publicClaims($recipe));
    }

    public function test_unknown_and_compound_ingredient_evidence_cannot_approve_a_vegan_claim(): void
    {
        $tag = $this->claimTag('vegan');
        $contributor = User::factory()->contributor()->create();
        $reviewer = User::factory()->admin()->create();
        $recipe = $this->publicRecipe($contributor);
        $this->markIngredientsCompatible($recipe, $tag, $reviewer);
        $ingredient = $recipe->recipeIngredients()->first()->ingredient;

        $unresolved = IngredientComponent::query()->create([
            'ingredient_id' => $ingredient->getKey(),
            'unresolved_component' => 'Unspecified processing aid',
        ]);

        $this->assertSame(DietaryCompatibility::Unknown, app(IngredientDietaryResolver::class)->resolve($recipe, $tag));

        IngredientComponent::query()->whereKey($unresolved->getKey())->delete();
        $component = Ingredient::factory()->create();
        IngredientComponent::query()->create([
            'ingredient_id' => $ingredient->getKey(),
            'component_ingredient_id' => $component->getKey(),
        ]);

        $this->assertSame(DietaryCompatibility::Unknown, app(IngredientDietaryResolver::class)->resolve($recipe, $tag));

        Auth::login($reviewer);
        IngredientDietaryProfile::query()->create([
            'ingredient_id' => $component->getKey(),
            'composition_status' => IngredientCompositionStatus::Complete,
            'origin_classification' => IngredientOriginClassification::AnimalDerivedOther,
            'evidence' => ['Manufacturer ingredient specification'],
        ]);
        IngredientDietaryAssessment::query()->create([
            'ingredient_id' => $component->getKey(),
            'tag_id' => $tag->getKey(),
            'outcome' => DietaryCompatibility::Compatible,
            'evidence' => ['Manufacturer ingredient specification'],
            'rule_version' => 1,
        ]);
        Auth::logout();

        $this->assertSame(DietaryCompatibility::Incompatible, app(IngredientDietaryResolver::class)->resolve($recipe, $tag));
        $this->assertStringContainsString('conflict', implode(' ', app(IngredientDietaryResolver::class)->warnings($recipe, $tag)));
        $this->assertStringContainsString('Manufacturer ingredient specification', implode(' ', app(IngredientDietaryResolver::class)->evidence($recipe, $tag)));

        $claim = app(DietaryClaimService::class)->suggest($contributor, $recipe, $tag, DietaryVerificationMethod::EditorialIngredientReview);
        $this->expectException(ValidationException::class);
        app(DietaryClaimService::class)->approve($reviewer, $claim, DietaryVerificationMethod::EditorialIngredientReview);
    }

    public function test_ingredient_metadata_changes_hide_and_audit_stale_claims(): void
    {
        $this->enablePublicClaims('vegetarian');
        $tag = $this->claimTag();
        $recipe = $this->publicRecipe();
        $claim = $this->approved($recipe, $tag);
        $ingredientId = (int) $recipe->recipeIngredients()->first()->ingredient_id;

        $this->assertCount(1, app(DietaryClaimVisibility::class)->publicClaims($recipe));

        DB::table('ingredient_dietary_profiles')->where('ingredient_id', $ingredientId)->update([
            'origin_classification' => 'animal_flesh',
            'updated_at' => now(),
        ]);

        $this->assertSame(DietaryClaimStatus::Approved, $claim->fresh()->status);
        $this->assertCount(0, app(DietaryClaimVisibility::class)->publicClaims($recipe));

        app(DietaryClaimService::class)->revalidate($recipe, 'Ingredient composition or origin metadata changed.');

        $this->assertSame(DietaryClaimStatus::Invalidated, $claim->fresh()->status);
        $this->assertDatabaseHas('dietary_claim_audits', [
            'dietary_claim_id' => $claim->getKey(),
            'action' => 'invalidated',
            'notes' => 'Ingredient composition or origin metadata changed.',
        ]);
    }

    public function test_approved_claim_stays_bound_to_a_draft_until_republication_rechecks_it(): void
    {
        $this->enablePublicClaims('vegetarian');
        $tag = $this->claimTag();
        $recipe = $this->publicRecipe();
        $claim = $this->approved($recipe, $tag);

        $recipe->update(['status' => 'draft', 'visibility' => 'private']);
        RecipeIngredient::query()->where('recipe_id', $recipe->getKey())->update(['quantity' => '375.0000']);

        $this->assertSame(DietaryClaimStatus::Approved, $claim->fresh()->status);
        $this->assertCount(0, app(DietaryClaimVisibility::class)->publicClaims($recipe->refresh()));

        $recipe->update(['status' => 'published', 'visibility' => 'public', 'published_at' => now()]);

        $this->assertSame(DietaryClaimStatus::Invalidated, $claim->fresh()->status);
        $this->assertDatabaseHas('dietary_claim_audits', [
            'dietary_claim_id' => $claim->getKey(),
            'action' => 'invalidated',
            'notes' => 'Recipe publication state changed; the approved revision was checked before publication.',
        ]);
    }

    public function test_dietary_metadata_changes_invalidate_only_affected_claims(): void
    {
        $vegetarian = $this->claimTag('vegetarian');
        $vegan = $this->claimTag('vegan');
        $contributor = User::factory()->contributor()->create();
        $reviewer = User::factory()->admin()->create();
        $recipe = $this->publicRecipe($contributor);
        $ingredientId = (int) $recipe->recipeIngredients()->first()->ingredient_id;

        Auth::login($reviewer);
        IngredientDietaryProfile::query()->create([
            'ingredient_id' => $ingredientId,
            'composition_status' => IngredientCompositionStatus::Complete,
            'origin_classification' => IngredientOriginClassification::NotAnimalDerived,
            'evidence' => ['Ingredient documentation'],
        ]);
        foreach ([$vegetarian, $vegan] as $tag) {
            IngredientDietaryAssessment::query()->create([
                'ingredient_id' => $ingredientId,
                'tag_id' => $tag->getKey(),
                'outcome' => DietaryCompatibility::Compatible,
                'evidence' => ['Ingredient documentation'],
                'rule_version' => 1,
            ]);
        }
        Auth::logout();

        $service = app(DietaryClaimService::class);
        $vegetarianClaim = $service->approve(
            $reviewer,
            $service->suggest($contributor, $recipe, $vegetarian),
            DietaryVerificationMethod::EditorialIngredientReview,
        );
        $veganClaim = $service->approve(
            $reviewer,
            $service->suggest($contributor, $recipe, $vegan),
            DietaryVerificationMethod::EditorialIngredientReview,
        );

        DB::table('ingredient_dietary_assessments')
            ->where('ingredient_id', $ingredientId)
            ->where('tag_id', $vegetarian->getKey())
            ->update(['outcome' => 'incompatible', 'updated_at' => now()]);

        $this->assertSame(1, $service->revalidate($recipe, 'Vegetarian ingredient assessment changed.'));
        $this->assertSame(DietaryClaimStatus::Invalidated, $vegetarianClaim->fresh()->status);
        $this->assertSame(DietaryClaimStatus::Approved, $veganClaim->fresh()->status);
    }

    public function test_what_can_i_cook_filters_only_by_current_public_claims(): void
    {
        $this->enablePublicClaims('vegetarian');
        $tag = $this->claimTag();
        $recipe = $this->publicRecipe();
        $this->approved($recipe, $tag);
        $ingredientId = (int) $recipe->recipeIngredients()->first()->ingredient_id;

        Livewire::test(WhatCanICook::class, ['locale' => 'en'])
            ->set('selectedIngredientIds', [$ingredientId])
            ->set('dietaryClaim', 'vegetarian')
            ->assertViewHas('results', fn ($results): bool => $results->pluck('recipe.id')->contains($recipe->getKey()));
    }

    public function test_meal_planner_search_uses_only_current_public_dietary_claims(): void
    {
        $this->enablePublicClaims('vegetarian');
        $tag = $this->claimTag();
        $contributor = User::factory()->contributor()->create();
        $reviewer = User::factory()->admin()->create();
        $recipe = $this->publicRecipe($contributor);
        $this->markIngredientsCompatible($recipe, $tag, $reviewer);
        $claimService = app(DietaryClaimService::class);
        $claimService->approve(
            $reviewer,
            $claimService->suggest($contributor, $recipe, $tag, DietaryVerificationMethod::EditorialIngredientReview),
            DietaryVerificationMethod::EditorialIngredientReview,
        );

        $this->assertSame(
            [$recipe->getKey()],
            app(MealPlanService::class)->searchRecipes($contributor, null, 'Lentil', 'en', 8, 'vegetarian')->modelKeys(),
        );

        RecipeIngredient::query()->where('recipe_id', $recipe->getKey())->update(['quantity' => '999.0000']);

        $this->assertSame([], app(MealPlanService::class)->searchRecipes($contributor, null, 'Lentil', 'en', 8, 'vegetarian')->modelKeys());
    }

    public function test_verified_readers_can_report_a_current_public_claim(): void
    {
        $this->enablePublicClaims('vegetarian');
        $tag = $this->claimTag();
        $recipe = $this->publicRecipe();
        $claim = $this->approved($recipe, $tag);
        $reporter = User::factory()->contributor()->create();

        $this->actingAs($reporter)
            ->post(route('recipes.dietary-claims.report', [
                'locale' => 'en',
                'slug' => $recipe->slug,
                'claim' => $claim->getKey(),
            ]), ['notes' => 'This claim appears to conflict with the ingredient list.'])
            ->assertRedirect(route('recipes.show', ['locale' => 'en', 'slug' => $recipe->slug]));

        $this->assertDatabaseHas('dietary_claim_audits', [
            'dietary_claim_id' => $claim->getKey(),
            'action' => 'flagged',
            'actor_id' => $reporter->getKey(),
            'notes' => 'This claim appears to conflict with the ingredient list.',
        ]);
        $this->assertSame(DietaryClaimStatus::Approved, $claim->fresh()->status);
    }

    public function test_claim_report_is_rejected_when_the_claim_is_not_publicly_released(): void
    {
        $tag = $this->claimTag();
        $recipe = $this->publicRecipe();
        $claim = $this->approved($recipe, $tag);
        $reporter = User::factory()->contributor()->create();

        $this->actingAs($reporter)
            ->post(route('recipes.dietary-claims.report', [
                'locale' => 'en',
                'slug' => $recipe->slug,
                'claim' => $claim->getKey(),
            ]), ['notes' => 'The claim needs another review.'])
            ->assertNotFound();

        $this->assertSame(2, $claim->audits()->count());
    }

    public function test_only_administrators_can_approve_and_editors_can_flag_but_not_approve(): void
    {
        $tag = $this->claimTag();
        $recipe = $this->publicRecipe();
        $contributor = User::factory()->contributor()->create();
        $recipe->update(['author_id' => $contributor->getKey()]);
        $service = app(DietaryClaimService::class);
        $claim = $service->suggest($contributor, $recipe->refresh(), $tag, DietaryVerificationMethod::EditorialIngredientReview);

        foreach ([User::factory()->contributor()->create(), User::factory()->editor()->create(), User::factory()->create()] as $user) {
            try {
                $service->approve($user, $claim, DietaryVerificationMethod::EditorialIngredientReview);
                $this->fail('Approval should be denied.');
            } catch (AuthorizationException) {
                $this->assertSame(DietaryClaimStatus::Suggested, $claim->fresh()->status);
            }
        }

        $editor = User::factory()->editor()->create();
        $this->assertTrue($editor->can('view', $claim));
        $audit = $service->flag($editor, $claim, 'Ingredient list is incomplete.');
        $this->assertSame('flagged', $audit->action->value);
        $this->assertSame(DietaryClaimStatus::Suggested, $claim->fresh()->status);

        $this->expectException(AuthorizationException::class);
        $service->flag($contributor, $claim, 'Not allowed.');
    }

    public function test_editors_can_open_the_dietary_claim_review_resource_without_decision_actions(): void
    {
        $tag = $this->claimTag();
        $contributor = User::factory()->contributor()->create();
        $recipe = $this->publicRecipe($contributor);
        $claim = app(DietaryClaimService::class)->suggest($contributor, $recipe, $tag);
        $editor = User::factory()->editor()->create();

        $this->actingAs($editor)
            ->get('/admin/dietary-claims')
            ->assertOk()
            ->assertSee('Lentil stew')
            ->assertDontSee('approve');

        $this->assertSame(DietaryClaimStatus::Suggested, $claim->fresh()->status);
    }

    public function test_reviewer_cannot_approve_their_own_suggestion_in_app_or_database(): void
    {
        $tag = $this->claimTag();
        $admin = User::factory()->admin()->create();
        $recipe = $this->publicRecipe($admin);
        $service = app(DietaryClaimService::class);
        $claim = $service->suggest($admin, $recipe, $tag, DietaryVerificationMethod::EditorialIngredientReview);

        try {
            $service->approve($admin, $claim, DietaryVerificationMethod::EditorialIngredientReview);
            $this->fail('Self-approval should be denied.');
        } catch (AuthorizationException) {
            $this->assertSame(DietaryClaimStatus::Suggested, $claim->fresh()->status);
        }

        $this->expectException(QueryException::class);
        DB::table('recipe_dietary_claims')->where('id', $claim->id)->update(['status' => 'approved', 'reviewed_by' => $admin->id, 'reviewed_at' => now(), 'verification_method' => 'editorial_ingredient_review']);
    }

    public function test_contributors_can_only_suggest_for_recipes_they_own(): void
    {
        $tag = $this->claimTag();
        $recipe = $this->publicRecipe();

        $this->expectException(AuthorizationException::class);
        app(DietaryClaimService::class)->suggest(User::factory()->contributor()->create(), $recipe, $tag);
    }

    public function test_high_risk_claims_need_evidence_a_qualified_method_and_the_high_risk_permission(): void
    {
        $tag = $this->claimTag('gluten-free', 'ingredient_allergen', false);
        $contributor = User::factory()->contributor()->create();
        $recipe = $this->publicRecipe($contributor);
        $service = app(DietaryClaimService::class);
        $claim = $service->suggest($contributor, $recipe, $tag, DietaryVerificationMethod::EditorialIngredientReview);
        $admin = User::factory()->admin()->create();

        foreach ([[DietaryVerificationMethod::EditorialIngredientReview, ['Supplier sheet']], [DietaryVerificationMethod::LaboratoryTest, []]] as [$method, $evidence]) {
            try {
                $service->approve($admin, $claim, $method, $evidence);
                $this->fail('High-risk approval without evidence and a qualified method should fail.');
            } catch (ValidationException) {
                $this->assertSame(DietaryClaimStatus::Suggested, $claim->fresh()->status);
            }
        }

        $reviewerWithoutHighRisk = User::factory()->create();
        $reviewerWithoutHighRisk->givePermissionTo(AppPermissions::DIETARY_CLAIMS_REVIEW);
        $this->assertFalse($reviewerWithoutHighRisk->can('approve', $claim));

        $service->approve($admin, $claim, DietaryVerificationMethod::LaboratoryTest, ['Lab report 2026-11-01']);
        $this->assertSame(DietaryClaimStatus::Approved, $claim->fresh()->status);

        // Approved, but the tag is not enabled for public display yet.
        $this->assertSame([], app(DietaryClaimVisibility::class)->publicClaims($recipe)->all());
        $tag->update(['is_public_enabled' => true]);
        $this->assertCount(0, app(DietaryClaimVisibility::class)->publicClaims($recipe));
    }

    public function test_a_dedicated_review_permission_can_later_authorize_a_qualified_reviewer(): void
    {
        $tag = $this->claimTag();
        $contributor = User::factory()->contributor()->create();
        $recipe = $this->publicRecipe($contributor);
        $claim = app(DietaryClaimService::class)->suggest($contributor, $recipe, $tag, DietaryVerificationMethod::EditorialIngredientReview);
        $reviewer = User::factory()->editor()->create();

        $this->assertFalse($reviewer->can('approve', $claim));
        $reviewer->givePermissionTo(AppPermissions::DIETARY_CLAIMS_REVIEW);
        $this->assertTrue($reviewer->fresh()->can('approve', $claim));
    }

    public function test_changing_quantities_invalidates_the_claim_and_hides_it(): void
    {
        $tag = $this->claimTag();
        $recipe = $this->publicRecipe();
        $claim = $this->approved($recipe, $tag);

        RecipeIngredient::query()->where('recipe_id', $recipe->id)->first()->update(['quantity' => '350.0000']);

        $this->assertSame(DietaryClaimStatus::Invalidated, $claim->fresh()->status);
        $this->assertSame(['approved', 'invalidated'], DietaryClaimAudit::query()->where('dietary_claim_id', $claim->id)->whereIn('action', ['approved', 'invalidated'])->orderBy('id')->pluck('new_status')->all());
        $this->get($this->url($recipe))->assertOk()->assertDontSee('Vegetarian Label');
    }

    public function test_changing_instructions_or_confirmed_substitutions_invalidates_the_claim(): void
    {
        $tag = $this->claimTag();
        $recipe = $this->publicRecipe();
        $claim = $this->approved($recipe, $tag);

        $translation = $recipe->steps()->first()->translations()->where('locale', 'en')->first();
        $translation->instruction = 'Fry the lentils in butter.';
        $translation->save();
        $this->assertSame(DietaryClaimStatus::Invalidated, $claim->fresh()->status);

        $second = $this->claimTag('vegan');
        $claim = $this->approved($recipe->refresh(), $second);
        $line = $recipe->recipeIngredients()->first();
        IngredientSubstitution::factory()->create(['recipe_id' => $recipe->id, 'ingredient_id' => $line->ingredient_id, 'status' => 'approved']);

        $this->assertSame(DietaryClaimStatus::Invalidated, $claim->fresh()->status);
    }

    public function test_unrelated_edits_do_not_invalidate_a_claim(): void
    {
        $this->enablePublicClaims('vegetarian');
        $tag = $this->claimTag();
        $recipe = $this->publicRecipe();
        $claim = $this->approved($recipe, $tag);

        $recipe->translations()->where('locale', 'en')->update(['title' => 'A new title']);
        $recipe->update(['is_featured' => true, 'prep_minutes' => 99]);
        RecipeIngredient::query()->where('recipe_id', $recipe->id)->first()->touch();
        $ingredientId = (int) $recipe->recipeIngredients()->first()->ingredient_id;
        $allergen = IngredientAllergenAssessment::query()->create([
            'ingredient_id' => $ingredientId,
            'allergen_code' => 'milk',
            'presence' => 'unknown',
        ]);
        $allergen->update(['presence' => 'may_contain']);

        $this->assertSame(DietaryClaimStatus::Approved, $claim->fresh()->status);
        $this->assertCount(1, app(DietaryClaimVisibility::class)->publicClaims($recipe->refresh()));
    }

    public function test_a_stale_claim_is_hidden_even_if_no_invalidation_hook_ran(): void
    {
        $tag = $this->claimTag();
        $recipe = $this->publicRecipe();
        $claim = $this->approved($recipe, $tag);

        DB::table('recipe_ingredients')->where('recipe_id', $recipe->id)->update(['quantity' => '999.0000']);

        $this->assertSame(DietaryClaimStatus::Approved, $claim->fresh()->status);
        $this->assertCount(0, app(DietaryClaimVisibility::class)->publicClaims($recipe));
        Livewire::test(RecipeIndex::class, ['locale' => 'en'])->set('tag', 'vegetarian')->assertViewHas('recipes', fn ($recipes) => $recipes->isEmpty());
        Livewire::test(RecipeIndex::class, ['locale' => 'en'])->set('dietary', 'vegetarian')->assertViewHas('recipes', fn ($recipes) => $recipes->isEmpty())->assertViewHas('recipeClaims', []);
    }

    public function test_changing_the_rule_version_invalidates_approvals(): void
    {
        $tag = $this->claimTag();
        $recipe = $this->publicRecipe();
        $claim = $this->approved($recipe, $tag);

        config(['dietary.claims.vegetarian.rule_version' => 2]);

        $this->assertCount(0, app(DietaryClaimVisibility::class)->publicClaims($recipe));
        $this->assertSame(0, Artisan::call('dietary:revalidate'));
        $this->assertSame(DietaryClaimStatus::Invalidated, $claim->fresh()->status);
    }

    public function test_a_suggestion_made_before_a_recipe_change_cannot_be_approved(): void
    {
        $tag = $this->claimTag();
        $contributor = User::factory()->contributor()->create();
        $recipe = $this->publicRecipe($contributor);
        $service = app(DietaryClaimService::class);
        $claim = $service->suggest($contributor, $recipe, $tag, DietaryVerificationMethod::EditorialIngredientReview);

        RecipeIngredient::query()->where('recipe_id', $recipe->id)->first()->update(['quantity' => '1.0000']);

        $this->expectException(ValidationException::class);
        $service->approve(User::factory()->admin()->create(), $claim, DietaryVerificationMethod::EditorialIngredientReview);
    }

    public function test_suspension_hides_a_claim_and_is_audited(): void
    {
        $tag = $this->claimTag();
        $recipe = $this->publicRecipe();
        $claim = $this->approved($recipe, $tag);
        $service = app(DietaryClaimService::class);

        try {
            $service->suspend(User::factory()->editor()->create(), $claim, 'Reported by a reader.');
            $this->fail('Editors cannot suspend.');
        } catch (AuthorizationException) {
        }

        $admin = User::factory()->admin()->create();
        $service->suspend($admin, $claim, 'Reported by a reader.');

        $this->assertCount(0, app(DietaryClaimVisibility::class)->publicClaims($recipe));
        $this->assertDatabaseHas('dietary_claim_audits', ['dietary_claim_id' => $claim->id, 'action' => 'suspended', 'previous_status' => 'approved', 'new_status' => 'suspended', 'actor_id' => $admin->id]);
    }

    public function test_audit_trail_records_the_full_decision_context(): void
    {
        $tag = $this->claimTag();
        $recipe = $this->publicRecipe();
        $claim = $this->approved($recipe, $tag);
        $fingerprint = app(RecipeDietaryFingerprint::class)->for($recipe, $tag);

        $audit = $claim->audits()->where('action', 'approved')->first();

        $this->assertSame($recipe->id, (int) $audit->recipe_id);
        $this->assertSame($tag->id, (int) $audit->tag_id);
        $this->assertSame('suggested', $audit->previous_status);
        $this->assertSame('approved', $audit->new_status);
        $this->assertSame($claim->reviewed_by, (int) $audit->actor_id);
        $this->assertSame($fingerprint, $audit->revision_hash);
        $this->assertSame(1, $audit->rule_version);
        $this->assertSame('editorial_ingredient_review', $audit->verification_method);
        $this->assertSame('Checked the ingredient list.', $audit->notes);
        $this->assertNotNull($audit->created_at);
        $this->assertCount(2, $claim->audits);
    }

    public function test_audit_records_are_append_only_in_the_application_and_the_database(): void
    {
        $recipe = $this->publicRecipe();
        $claim = $this->approved($recipe, $this->claimTag());
        $audit = $claim->audits()->first();

        try {
            $audit->update(['notes' => 'tampered']);
            $this->fail('Application-level update should be refused.');
        } catch (LogicException) {
        }

        try {
            $audit->delete();
            $this->fail('Application-level delete should be refused.');
        } catch (LogicException) {
        }

        foreach ([
            fn () => DB::table('dietary_claim_audits')->where('id', $audit->id)->update(['notes' => 'tampered']),
            fn () => DB::table('dietary_claim_audits')->where('id', $audit->id)->delete(),
        ] as $attempt) {
            DB::beginTransaction();

            try {
                $attempt();
                $this->fail('The database should refuse changes to audit rows.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('append-only', $exception->getMessage());
            } finally {
                DB::rollBack();
            }
        }
    }

    public function test_claims_are_not_shown_for_private_recipes(): void
    {
        $tag = $this->claimTag();
        $owner = User::factory()->contributor()->create();
        $recipe = $this->publicRecipe($owner);
        $reviewer = User::factory()->admin()->create();
        $this->markIngredientsCompatible($recipe, $tag, $reviewer);
        $service = app(DietaryClaimService::class);
        $claim = $service->suggest($owner, $recipe, $tag, DietaryVerificationMethod::EditorialIngredientReview);
        $service->approve($reviewer, $claim, DietaryVerificationMethod::EditorialIngredientReview);

        $recipe->update(['visibility' => 'private']);

        $this->assertCount(0, app(DietaryClaimVisibility::class)->publicClaims($recipe->refresh()));
        $this->assertFalse(User::factory()->editor()->create()->can('view', $claim->fresh()));
        $this->get($this->url($recipe))->assertNotFound();
        $this->actingAs($owner)->get($this->url($recipe))->assertOk()->assertDontSee('Vegetarian Label');
    }

    public function test_personal_substitutions_do_not_change_or_inherit_claims(): void
    {
        $tag = $this->claimTag();
        $recipe = $this->publicRecipe();
        $claim = $this->approved($recipe, $tag);
        $before = app(RecipeDietaryFingerprint::class)->for($recipe);

        UserRecipeIngredientChange::factory()->create(['recipe_ingredient_id' => $recipe->recipeIngredients()->first()->id]);

        // The approval stays attached to the published recipe; nothing is copied to personal variants.
        $this->assertSame($before, app(RecipeDietaryFingerprint::class)->for($recipe));
        $this->assertSame(DietaryClaimStatus::Approved, $claim->fresh()->status);
        $this->assertSame(1, DietaryClaim::query()->count());
    }

    public function test_fingerprint_ignores_titles_and_ordering_but_tracks_ingredients(): void
    {
        $recipe = $this->publicRecipe();
        $fingerprint = app(RecipeDietaryFingerprint::class);
        $before = $fingerprint->for($recipe);

        $recipe->translations()->where('locale', 'en')->update(['title' => 'Different']);
        $this->assertSame($before, $fingerprint->for($recipe));

        RecipeIngredient::factory()->create(['recipe_id' => $recipe->id]);
        $this->assertNotSame($before, $fingerprint->for($recipe));
    }

    public function test_existing_dietary_tags_and_associations_are_kept_by_the_classification_migration(): void
    {
        $this->artisan('db:seed', ['--class' => 'TagSeeder'])->assertSuccessful();

        $this->assertSame(TagClassification::DietStyle, Tag::where('code', 'vegan')->value('classification'));
        $this->assertSame(TagClassification::IngredientAllergen, Tag::where('code', 'gluten-free')->value('classification'));
        $this->assertFalse((bool) Tag::where('code', 'gluten-free')->value('is_public_enabled'));
        $this->assertTrue((bool) Tag::where('code', 'vegetarian')->value('is_public_enabled'));
        $this->assertSame(TagClassification::Descriptive, Tag::where('code', 'quick')->value('classification'));

        Tag::where('code', 'gluten-free')->update(['is_public_enabled' => true]);
        $this->artisan('db:seed', ['--class' => 'TagSeeder'])->assertSuccessful();
        $this->assertTrue((bool) Tag::where('code', 'gluten-free')->value('is_public_enabled'));
    }
}
