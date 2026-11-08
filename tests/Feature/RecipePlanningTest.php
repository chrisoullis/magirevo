<?php

namespace Tests\Feature;

use App\Livewire\RecipePlanning;
use App\Models\Household;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\User;
use App\Services\RecipePublishingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RecipePlanningTest extends TestCase
{
    use RefreshDatabase;

    private function recipe(): Recipe
    {
        return app(RecipePublishingService::class)->publish(Recipe::factory()->complete()->create())->fresh();
    }

    public function test_planning_uses_existing_revision_and_selected_servings_without_creating_a_shopping_list(): void
    {
        $recipe = $this->recipe();
        $user = User::factory()->create();
        Livewire::actingAs($user)->test(RecipePlanning::class, ['recipeId' => $recipe->id, 'locale' => 'en'])
            ->set('date', '2026-11-05')->set('servings', 6)->call('addToPlan')->assertHasNoErrors()->assertSet('added', true)
            ->assertSet('plannerUrl', '/en/planner?week=2026-11-02')->call('addToPlan');
        $this->assertDatabaseCount('meal_plan_entries', 1);
        $this->assertDatabaseHas('meal_plan_entries', ['recipe_id' => $recipe->id, 'planned_recipe_revision_id' => $recipe->current_revision_id, 'servings' => 6]);
        $this->assertDatabaseCount('shopping_lists', 0);
    }

    public function test_shopping_action_schedules_and_generates_real_quantities(): void
    {
        $recipe = $this->recipe();
        Livewire::actingAs(User::factory()->create())->test(RecipePlanning::class, ['recipeId' => $recipe->id, 'locale' => 'el'])
            ->set('date', '2026-11-05')->set('servings', 8)->call('addToShopping')->assertHasNoErrors()
            ->assertSet('shoppingUrl', '/el/planner/shopping?week=2026-11-02')->assertSee('λίστα αγορών');
        $this->assertDatabaseCount('meal_plan_entries', 1);
        $this->assertDatabaseCount('shopping_lists', 1);
        $this->assertDatabaseHas('shopping_list_items', ['ingredient_id' => $recipe->recipeIngredients()->first()->ingredient_id, 'is_manual' => false, 'quantity' => 4]);
        $this->assertSame(8, MealPlanEntry::query()->first()->servings);
    }

    public function test_guests_unverified_accounts_and_inaccessible_recipes_cannot_write(): void
    {
        $recipe = $this->recipe();
        Livewire::test(RecipePlanning::class, ['recipeId' => $recipe->id, 'locale' => 'en'])->call('addToPlan')->assertForbidden();
        Livewire::actingAs(User::factory()->unverified()->create())->test(RecipePlanning::class, ['recipeId' => $recipe->id, 'locale' => 'en'])->call('addToShopping')->assertForbidden();
        Livewire::actingAs(User::factory()->create())->test(RecipePlanning::class, ['recipeId' => Recipe::factory()->create()->id, 'locale' => 'en'])->assertNotFound();
        $this->assertDatabaseCount('meal_plans', 0);
    }

    public function test_household_viewers_cannot_add_and_private_recipes_must_be_visible_to_every_member(): void
    {
        $actor = User::factory()->create();
        $other = User::factory()->create();
        $household = Household::factory()->create();
        $membership = $household->members()->create(['user_id' => $actor->id, 'role' => 'viewer']);
        $household->members()->create(['user_id' => $other->id, 'role' => 'member']);
        $recipe = $this->recipe();
        Livewire::actingAs($actor)->test(RecipePlanning::class, ['recipeId' => $recipe->id, 'locale' => 'en'])
            ->set('householdId', $household->id)->call('addToShopping')->assertForbidden();
        $membership->update(['role' => 'member']);
        $private = Recipe::factory()->create(['author_id' => $actor->id]);
        Livewire::actingAs($actor)->test(RecipePlanning::class, ['recipeId' => $private->id, 'locale' => 'en'])
            ->set('householdId', $household->id)->call('addToPlan')->assertHasErrors('recipe');
        $this->assertDatabaseCount('meal_plans', 0);
        Livewire::actingAs($actor)->test(RecipePlanning::class, ['recipeId' => $recipe->id, 'locale' => 'en'])
            ->set('householdId', $household->id)->call('addToPlan')->assertHasNoErrors();
        $this->assertDatabaseHas('meal_plans', ['household_id' => $household->id]);
    }

    public function test_invalid_meal_date_and_servings_do_not_create_plans(): void
    {
        $recipe = $this->recipe();
        Livewire::actingAs(User::factory()->create())->test(RecipePlanning::class, ['recipeId' => $recipe->id, 'locale' => 'en'])
            ->set('meal', 'invalid')->set('date', 'not-a-date')->set('servings', 49)->call('addToPlan')
            ->assertHasErrors(['date', 'meal', 'servings']);
        $this->assertDatabaseCount('meal_plans', 0);
    }

    public function test_a_service_rejection_rolls_back_the_entire_plan_and_shopping_operation(): void
    {
        config(['planner.max_entries_per_plan' => 0]);
        $recipe = $this->recipe();
        Livewire::actingAs(User::factory()->create())->test(RecipePlanning::class, ['recipeId' => $recipe->id, 'locale' => 'en'])
            ->call('addToShopping')->assertHasErrors('recipe')->assertSet('added', false);
        $this->assertDatabaseCount('meal_plans', 0);
        $this->assertDatabaseCount('meal_plan_entries', 0);
        $this->assertDatabaseCount('shopping_lists', 0);
    }
}
