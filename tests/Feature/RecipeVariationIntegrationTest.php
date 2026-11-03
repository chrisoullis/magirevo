<?php

namespace Tests\Feature;

use App\Actions\StoreRecipeMedia;
use App\Enums\HouseholdRole;
use App\Enums\MealType;
use App\Livewire\PersonalRecipeNotes;
use App\Livewire\RecipeRatings;
use App\Livewire\RecipeServings;
use App\Livewire\WhatCanICook;
use App\Models\Household;
use App\Models\Ingredient;
use App\Models\IngredientSubstitution;
use App\Models\Recipe;
use App\Models\RecipeRating;
use App\Models\User;
use App\Services\BatchCookService;
use App\Services\MealPlanService;
use App\Services\PlanIngredientCollector;
use App\Services\RecipeContentGuard;
use App\Services\RecipeRevisionService;
use App\Services\RecipeRevisionSnapshotter;
use App\Services\Recipes\RecipeForkService;
use App\Services\Recipes\RecipeVariationContent;
use App\Services\Recipes\RecipeVariationPublishingService;
use App\Services\ShoppingListService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\CreatesVariationSource;
use Tests\TestCase;

class RecipeVariationIntegrationTest extends TestCase
{
    use CreatesVariationSource;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['recipe-variations.enabled' => true]);
    }

    private function variation(?User $owner = null, ?callable $beforeFork = null): array
    {
        $owner ??= User::factory()->create();
        $source = $this->source();
        if ($beforeFork !== null) {
            $beforeFork($owner, $source);
        }
        $variation = app(RecipeForkService::class)->fork($owner, $source, ['idempotency_key' => (string) Str::uuid()]);
        $ingredient = Ingredient::factory()->create([
            'en' => ['name' => 'Secret pantry mushroom'],
            'el' => ['name' => 'Ιδιωτικό μανιτάρι'],
        ]);
        $payload = app(RecipeRevisionSnapshotter::class)->serialize($variation->id);
        $payload['translations']['en']['title'] = 'My private mushroom variation';
        $payload['translations']['el']['title'] = 'Η ιδιωτική μου παραλλαγή';
        $payload['recipe']['base_servings'] = 2;
        $payload['ingredients'] = [array_replace($payload['ingredients'][0], [
            'ingredient_id' => $ingredient->id, 'quantity' => '100', 'quantity_max' => null,
            'unit_id' => null, 'scaling_mode' => 'linear', 'is_optional' => false,
        ])];
        $variation = $this->save($owner, $variation, $payload);

        return [$owner, $source, $variation, $ingredient];
    }

    private function save(User $owner, Recipe $variation, array $payload): Recipe
    {
        unset($payload['media']);
        foreach ($payload['translations'] as &$translation) {
            unset($translation['slug']);
        }
        unset($translation);
        foreach ($payload['steps'] as &$step) {
            unset($step['media']);
        }
        unset($step);
        $content = app(RecipeVariationContent::class);

        return $content->save($owner, $variation, $payload, [], $content->version($variation));
    }

    public function test_owner_can_opt_into_private_matching_and_plan_the_variation(): void
    {
        [$owner, , $variation, $ingredient] = $this->variation();
        Livewire::actingAs($owner)->test(WhatCanICook::class, ['locale' => 'en'])
            ->set('ingredientSearch', 'Secret pantry')
            ->assertDontSee('Secret pantry mushroom')
            ->set('includeMyVariations', true)
            ->assertSee('Secret pantry mushroom')
            ->call('selectIngredient', $ingredient->id)
            ->assertSee('My private mushroom variation')
            ->assertSee('Your private variation')
            ->call('addToMealPlan', $variation->id)
            ->assertHasNoErrors()
            ->set('dietaryClaim', 'vegetarian')->assertDontSee('My private mushroom variation');
        $this->assertDatabaseHas('meal_plan_entries', ['recipe_id' => $variation->id, 'servings' => 2, 'added_by' => $owner->id]);
        $response = $this->get('/en/what-can-i-cook')->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_greek_matching_and_missing_ingredients_use_the_private_destination(): void
    {
        [$owner, , $variation, $ingredient] = $this->variation();
        $second = Ingredient::factory()->create(['en' => ['name' => 'Variation-only parsley'], 'el' => ['name' => 'Ιδιωτικός μαϊντανός']]);
        $payload = app(RecipeRevisionSnapshotter::class)->serialize($variation->id);
        $payload['ingredients'][] = array_replace($payload['ingredients'][0], ['id' => null, 'ingredient_id' => $second->id]);
        $variation = $this->save($owner, $variation, $payload);
        $plan = app(MealPlanService::class)->ensurePlan($owner, null, now());
        $list = app(ShoppingListService::class)->ensureList($owner, $plan);

        Livewire::actingAs($owner)->test(WhatCanICook::class, ['locale' => 'el'])
            ->set('includeMyVariations', true)
            ->set('ingredientSearch', 'Ιδιωτικό μανιτάρι')
            ->assertSee('Ιδιωτικό μανιτάρι')
            ->call('selectIngredient', $ingredient->id)
            ->assertSee('Η ιδιωτική μου παραλλαγή')
            ->call('addMissingIngredients', $variation->id)
            ->assertHasNoErrors();
        $this->assertDatabaseHas('shopping_list_items', ['shopping_list_id' => $list->id, 'ingredient_id' => $second->id, 'is_manual' => true]);
        $this->assertDatabaseMissing('shopping_list_items', ['shopping_list_id' => $list->id, 'ingredient_id' => $ingredient->id]);
    }

    public function test_crafted_filter_cannot_expose_another_owners_variation_or_ingredients(): void
    {
        [, , $variation, $ingredient] = $this->variation();
        Livewire::actingAs(User::factory()->create())->test(WhatCanICook::class, ['locale' => 'en'])
            ->set('includeMyVariations', true)
            ->set('ingredientSearch', 'Secret pantry')
            ->assertDontSee('Secret pantry mushroom')
            ->set('selectedIngredientIds', [$ingredient->id])
            ->assertDontSee('My private mushroom variation')
            ->call('addToMealPlan', $variation->id)->assertNotFound();
        $this->assertDatabaseMissing('meal_plan_entries', ['recipe_id' => $variation->id]);
    }

    public function test_guest_and_unverified_owner_cannot_enable_private_matching(): void
    {
        [$owner, , , $ingredient] = $this->variation();
        Livewire::test(WhatCanICook::class, ['locale' => 'en'])
            ->set('includeMyVariations', true)->set('selectedIngredientIds', [$ingredient->id])
            ->assertDontSee('My private mushroom variation')->assertDontSee('Secret pantry mushroom');
        $owner->forceFill(['email_verified_at' => null])->save();
        Livewire::actingAs($owner)->test(WhatCanICook::class, ['locale' => 'en'])
            ->set('includeMyVariations', true)->set('selectedIngredientIds', [$ingredient->id])
            ->assertDontSee('My private mushroom variation')->assertDontSee('Secret pantry mushroom');
    }

    public function test_filter_reset_and_feature_switch_remove_private_matches(): void
    {
        [$owner, , , $ingredient] = $this->variation();
        $component = Livewire::actingAs($owner)->test(WhatCanICook::class, ['locale' => 'en'])
            ->set('includeMyVariations', true)->set('selectedIngredientIds', [$ingredient->id])
            ->assertSee('My private mushroom variation');
        $component->call('clearFilters')->assertSet('includeMyVariations', false)->assertDontSee('My private mushroom variation');
        config(['recipe-variations.enabled' => false]);
        $component->set('includeMyVariations', true)->assertDontSee('My private mushroom variation')->assertDontSee('Secret pantry mushroom');
    }

    public function test_shopping_quantities_follow_private_edits_without_changing_the_source_or_checked_items(): void
    {
        [$owner, $source, $variation, $ingredient] = $this->variation();
        $originalSource = app(RecipeRevisionSnapshotter::class)->serialize($source->id);
        $plans = app(MealPlanService::class);
        $plan = $plans->ensurePlan($owner, null, now());
        $entry = $plans->addEntry($owner, $plan, $variation, now(), MealType::Dinner, 4);
        $shopping = app(ShoppingListService::class);
        $list = $shopping->ensureList($owner, $plan);
        $shopping->generate($owner, $list);
        $item = $list->items()->where('ingredient_id', $ingredient->id)->firstOrFail();
        $this->assertSame('200.0000', $item->quantity);
        $shopping->setChecked($owner, $item, $item->version, true);
        $payload = app(RecipeRevisionSnapshotter::class)->serialize($variation->id);
        $payload['ingredients'][0]['quantity'] = '150';
        $this->save($owner, $variation, $payload);
        $shopping->generate($owner, $list);
        $this->assertTrue($item->fresh()->isChecked());
        $this->assertSame('100.0000', $item->fresh()->extra_quantity);
        $this->assertSame($originalSource, app(RecipeRevisionSnapshotter::class)->serialize($source->id));
        $batch = app(BatchCookService::class)->createFromEntry($owner, $entry);
        $this->assertSame($variation->id, $batch->recipe_id);
        $this->assertNull($batch->recipe_revision_id);
    }

    public function test_household_membership_does_not_grant_access_to_a_private_variation(): void
    {
        [$owner, , $variation] = $this->variation();
        $member = User::factory()->create();
        $household = Household::factory()->ownedBy($owner)->withMember($member, HouseholdRole::Member)->create();
        $plans = app(MealPlanService::class);
        $plan = $plans->ensurePlan($owner, $household, now());
        $this->assertFalse($plans->recipeAddable($owner, $household, $variation));
        $this->assertTrue($plans->searchRecipes($owner, $household, 'private mushroom', 'en')->isEmpty());
        try {
            $plans->addEntry($owner, $plan, $variation, now(), MealType::Dinner, 2);
            $this->fail('Membership must not share private variations.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('recipe', $exception->errors());
        }
        $this->assertDatabaseMissing('meal_plan_entries', ['meal_plan_id' => $plan->id, 'recipe_id' => $variation->id]);
    }

    public function test_household_shopping_excludes_a_private_variation_even_from_a_preexisting_entry(): void
    {
        [$owner, , $variation] = $this->variation();
        $member = User::factory()->create();
        $household = Household::factory()->ownedBy($owner)->create();
        $plans = app(MealPlanService::class);
        $plan = $plans->ensurePlan($owner, $household, now());
        $entry = $plans->addEntry($owner, $plan, $variation, now(), MealType::Dinner, 2);
        $household->members()->create(['user_id' => $member->id, 'role' => HouseholdRole::Member]);
        $collected = app(PlanIngredientCollector::class)->collect($plan);
        $this->assertSame([], $collected['lines']);
        $this->assertSame([$entry->id], $collected['excluded_entry_ids']);
        $view = $plans->weekView($member, $plan, 'en')->flatten(2)->first();
        $this->assertFalse($view['available']);
        $this->assertNull($view['title']);
        $list = app(ShoppingListService::class)->ensureList($owner, $plan);
        app(ShoppingListService::class)->generate($owner, $list);
        $this->assertSame(0, $list->items()->count());
    }

    public function test_personal_notes_favorites_and_ratings_are_not_inherited_from_the_source(): void
    {
        [$owner, $source, $variation] = $this->variation(beforeFork: function (User $owner, Recipe $source): void {
            $owner->recipeNotes()->create(['recipe_id' => $source->id, 'body' => 'Source-only private note']);
            $owner->favoriteRecipes()->attach($source->id);
            $rating = new RecipeRating;
            $rating->forceFill(['recipe_id' => $source->id, 'user_id' => $owner->id, 'rating' => 5, 'rating_status' => 'active', 'review_status' => 'published'])->save();
        });
        Livewire::actingAs($owner)->test(PersonalRecipeNotes::class, ['recipeId' => $variation->id, 'locale' => 'en'])
            ->assertDontSee('Source-only private note')->set('body', 'My variation-only private note')->call('save')->assertHasNoErrors();
        $this->assertDatabaseHas('user_recipe_notes', ['user_id' => $owner->id, 'recipe_id' => $source->id, 'body' => 'Source-only private note']);
        $this->assertDatabaseHas('user_recipe_notes', ['user_id' => $owner->id, 'recipe_id' => $variation->id, 'body' => 'My variation-only private note']);
        $this->assertFalse($owner->favoriteRecipes()->where('recipes.id', $variation->id)->exists());
        $this->assertSame(0, $variation->ratings()->count());
        $this->assertFalse($owner->can('create', [RecipeRating::class, $variation]));
        $this->post(route('recipes.save', ['locale' => 'en', 'recipe' => $variation->id]))->assertNotFound();
    }

    public function test_personal_ingredient_changes_remain_scoped_and_are_archived_on_replacement(): void
    {
        $alternative = Ingredient::factory()->create();
        [$owner, $source, $variation] = $this->variation(beforeFork: function (User $owner, Recipe $source) use ($alternative): void {
            $owner->recipeIngredientChanges()->create(['recipe_id' => $source->id, 'recipe_ingredient_id' => $source->recipeIngredients()->firstOrFail()->id, 'alternative_ingredient_id' => $alternative->id, 'note' => 'Source-only swap']);
        });
        $sourceLine = $source->recipeIngredients()->firstOrFail();
        $line = $variation->recipeIngredients()->firstOrFail();
        Livewire::actingAs($owner)->test(RecipeServings::class, ['recipeId' => $variation->id, 'locale' => 'en', 'baseServings' => 2])
            ->assertDontSee('Source-only swap')->call('editPersonalChange', $line->id)
            ->set('alternativeIngredientId', $alternative->id)->set('personalNote', 'Variation-only swap')
            ->call('savePersonalChange', $line->id)->assertHasNoErrors();
        $payload = app(RecipeRevisionSnapshotter::class)->serialize($variation->id);
        $payload['ingredients'][0]['ingredient_id'] = Ingredient::factory()->create()->id;
        $this->save($owner, $variation, $payload);
        $this->assertDatabaseHas('archived_personal_ingredient_changes', ['recipe_id' => $variation->id, 'note' => 'Variation-only swap']);
        $this->assertDatabaseMissing('user_recipe_ingredient_changes', ['recipe_id' => $variation->id]);
        $this->assertDatabaseHas('user_recipe_ingredient_changes', ['recipe_id' => $source->id, 'recipe_ingredient_id' => $sourceLine->id, 'note' => 'Source-only swap']);
    }

    public function test_later_source_revisions_do_not_change_private_matching_or_planned_ingredients(): void
    {
        [$owner, $source, $variation, $ingredient] = $this->variation();
        $plans = app(MealPlanService::class);
        $plan = $plans->ensurePlan($owner, null, now());
        $plans->addEntry($owner, $plan, $variation, now(), MealType::Dinner, 2);
        $originalRevision = $variation->derivation->source_recipe_revision_id;
        $service = app(RecipeRevisionService::class);
        $editor = User::factory()->admin()->create();
        $draft = $service->createDraft($editor, $source);
        $payload = $draft->payload;
        $payload['ingredients'][0]['quantity'] = '999';
        $draft = $service->saveDraft($editor, $draft, $payload);
        $service->approve(User::factory()->admin()->create(), $service->submit($editor, $draft));
        $this->assertNotSame($originalRevision, $source->fresh()->current_revision_id);
        $this->assertSame($originalRevision, $variation->fresh()->derivation->source_recipe_revision_id);
        $lines = app(PlanIngredientCollector::class)->collect($plan)['lines'];
        $this->assertCount(1, $lines);
        $this->assertSame($ingredient->id, $lines[0]->ingredientId);
        $this->assertSame('100.0000', $lines[0]->quantity);
        Livewire::actingAs($owner)->test(WhatCanICook::class, ['locale' => 'en'])
            ->set('includeMyVariations', true)->set('selectedIngredientIds', [$ingredient->id])
            ->assertSee('My private mushroom variation');
    }

    public function test_general_substitutions_can_be_confirmed_for_an_owned_private_variation(): void
    {
        [$owner, , $variation, $ingredient] = $this->variation();
        $alternative = Ingredient::factory()->create(['en' => ['name' => 'Private mushroom alternative']]);
        $suggestion = IngredientSubstitution::factory()->approved()->create(['recipe_id' => null, 'ingredient_id' => $ingredient->id, 'replacement_ingredient_id' => $alternative->id]);
        Livewire::actingAs($owner)->test(WhatCanICook::class, ['locale' => 'en'])
            ->set('includeMyVariations', true)
            ->set('ingredientSearch', 'Private mushroom alternative')->assertSee('Private mushroom alternative')
            ->call('selectIngredient', $alternative->id)
            ->assertSee('My private mushroom variation')
            ->call('confirmSubstitution', $variation->id, $ingredient->id, $suggestion->id)
            ->assertHasNoErrors()->call('addToMealPlan', $variation->id)->assertHasNoErrors();
        $this->assertDatabaseHas('meal_plan_entry_substitutions', ['recipe_id' => $variation->id, 'ingredient_substitution_id' => $suggestion->id, 'replacement_ingredient_id' => $alternative->id]);
        $this->assertSame($ingredient->id, $variation->recipeIngredients()->firstOrFail()->ingredient_id);
    }

    public function test_reviewed_public_variation_works_in_matching_household_planning_favorites_and_ratings(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        config(['recipe-variations.public_submissions_enabled' => true]);
        [$owner, $source, $variation, $ingredient] = $this->variation(User::factory()->contributor()->create());
        $permission = $source->reusePermissions()->firstOrFail();
        $grant = $permission->replicate();
        $grant->forceFill(['supersedes_id' => $permission->id, 'mode' => 'personal_and_public_submission', 'allows_public_adaptation' => true])->save();
        app(StoreRecipeMedia::class)->handle($variation, UploadedFile::fake()->image('variation-cover.png', 600, 400), ['en' => ['alt_text' => 'My mushrooms'], 'el' => ['alt_text' => 'Τα μανιτάρια μου']], 'cover');
        app(RecipeContentGuard::class)->setMode('enforce');
        $publishing = app(RecipeVariationPublishingService::class);
        $revision = $publishing->submit($owner, $variation, app(RecipeVariationContent::class)->version($variation), [
            'rights_confirmed' => true, 'meaningful_changes_confirmed' => true,
            'summaries' => ['en' => 'Changed the primary ingredient and quantity to develop a mushroom dish.', 'el' => 'Άλλαξα το κύριο υλικό και την ποσότητα για να φτιάξω ένα πιάτο με μανιτάρια.'],
        ]);
        $reviewer = User::factory()->admin()->create();
        $publishing->reviewRights($reviewer, $revision, true, 'Inspected the retained consent and applicable publication conditions.', true);
        app(RecipeRevisionService::class)->approve($reviewer, $revision, 'Independently reviewed the new primary ingredient and recipe.', true);
        $variation = $variation->fresh();
        Livewire::test(WhatCanICook::class, ['locale' => 'en'])->set('selectedIngredientIds', [$ingredient->id])->assertSee('My private mushroom variation');
        $member = User::factory()->create();
        $household = Household::factory()->ownedBy($owner)->withMember($member, HouseholdRole::Member)->create();
        $plans = app(MealPlanService::class);
        $plan = $plans->ensurePlan($member, $household, now());
        $entry = $plans->addEntry($member, $plan, $variation, now(), MealType::Dinner, 2);
        $this->assertSame($revision->id, $entry->planned_recipe_revision_id);
        $this->assertSame($ingredient->id, app(PlanIngredientCollector::class)->collect($plan)['lines'][0]->ingredientId);
        $this->actingAs($member)->post(route('recipes.save', ['locale' => 'en', 'recipe' => $variation->id]))->assertRedirect();
        $this->assertTrue($member->favoriteRecipes()->where('recipes.id', $variation->id)->exists());
        $this->assertFalse($member->favoriteRecipes()->where('recipes.id', $source->id)->exists());
        $this->assertTrue($member->can('create', [RecipeRating::class, $variation]));
        $this->assertFalse($owner->can('create', [RecipeRating::class, $variation]));
        Livewire::actingAs($member)->test(RecipeRatings::class, ['recipeId' => $variation->id, 'locale' => 'en'])
            ->set('rating', 4)->call('save')->assertHasNoErrors();
        $this->assertSame(1, $variation->ratings()->count());
        $this->assertSame(0, $source->ratings()->count());
    }

    public function test_retained_private_variation_still_matches_after_its_source_is_removed(): void
    {
        [$owner, $source, $variation, $ingredient] = $this->variation();
        $source->delete();
        Livewire::actingAs($owner)->test(WhatCanICook::class, ['locale' => 'en'])
            ->set('includeMyVariations', true)->set('selectedIngredientIds', [$ingredient->id])
            ->assertSee('My private mushroom variation')
            ->call('addToMealPlan', $variation->id)->assertHasNoErrors();
        $plan = app(MealPlanService::class)->findPlan($owner, null, now());
        $lines = app(PlanIngredientCollector::class)->collect($plan)['lines'];
        $this->assertCount(1, $lines);
        $this->assertSame($ingredient->id, $lines[0]->ingredientId);
        $this->assertSame($source->id, $variation->fresh()->derived_from_recipe_id);
    }

    public function test_source_specific_substitution_approvals_are_not_used_for_a_variation(): void
    {
        [$owner, $source, $variation] = $this->variation();
        $ingredientId = $source->recipeIngredients()->firstOrFail()->ingredient_id;
        $payload = app(RecipeRevisionSnapshotter::class)->serialize($variation->id);
        $payload['ingredients'][0]['ingredient_id'] = $ingredientId;
        $variation = $this->save($owner, $variation, $payload);
        $alternative = Ingredient::factory()->create();
        $substitution = IngredientSubstitution::factory()->approved()->create([
            'recipe_id' => $source->id, 'ingredient_id' => $ingredientId, 'replacement_ingredient_id' => $alternative->id,
        ]);
        $choices = [$ingredientId => ['substitution_id' => $substitution->id, 'replacement_ingredient_id' => $alternative->id]];
        $this->expectException(ValidationException::class);
        app(MealPlanService::class)->validatedSubstitutionChoices($variation, $choices);
    }
}
