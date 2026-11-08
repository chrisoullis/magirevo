<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\MealPlan;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\RecipeShare;
use App\Models\ShoppingList;
use App\Models\User;
use App\Services\RecipePublishingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MyKitchenDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_requires_a_verified_account_and_is_never_indexed_or_cached(): void
    {
        $this->get('/en/my-kitchen')->assertRedirect('/login');
        $this->actingAs(User::factory()->unverified()->create())->get('/en/my-kitchen')->assertRedirect('/email/verify');
        $user = User::factory()->create(['name' => 'Eleni']);
        $this->actingAs($user)->get('/en/my-kitchen')->assertOk()->assertSee('Welcome to your kitchen, Eleni')
            ->assertSee('href="/en/my-kitchen"', false)->assertSee('noindex, nofollow, noarchive')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $this->get('/el/my-kitchen')->assertOk()->assertSee('Καλώς ήρθες στην κουζίνα σου, Eleni')->assertSee('/en/my-kitchen');
        $this->assertDatabaseCount('meal_plans', 0);
        $this->assertDatabaseCount('shopping_lists', 0);
    }

    public function test_recipe_previews_use_ownership_published_saves_and_active_shares_with_bounded_results(): void
    {
        config(['recipe-variations.enabled' => true]);
        $user = User::factory()->create();
        $other = User::factory()->create();
        for ($i = 0; $i < 6; $i++) {
            $recipe = Recipe::factory()->published()->create();
            $user->favoriteRecipes()->attach($recipe);
            Recipe::factory()->create(['author_id' => $user->id]);
        }
        $hiddenSave = Recipe::factory()->create();
        $user->favoriteRecipes()->attach($hiddenSave);
        $shared = Recipe::factory()->create(['visibility' => 'shared', 'author_id' => $other->id]);
        $grant = RecipeShare::create(['recipe_id' => $shared->id, 'user_id' => $user->id, 'shared_by' => $other->id]);
        $private = Recipe::factory()->create(['author_id' => $other->id]);
        $this->actingAs($user)->get('/en/my-kitchen')->assertOk()
            ->assertViewHas('counts', fn ($counts) => $counts['saved'] === 6 && $counts['originals'] === 6 && $counts['shared'] === 1)
            ->assertViewHas('saved', fn ($items) => $items->count() === 4 && ! $items->contains('id', $hiddenSave->id))
            ->assertViewHas('originals', fn ($items) => $items->count() === 4 && ! $items->contains('id', $private->id))
            ->assertViewHas('shared', fn ($items) => $items->modelKeys() === [$shared->id]);
        $grant->update(['revoked_at' => now()]);
        $this->get('/en/my-kitchen')->assertOk()->assertViewHas('shared', fn ($items) => $items->isEmpty())
            ->assertViewHas('counts', fn ($counts) => $counts['shared'] === 0);
    }

    public function test_planner_shopping_and_invitations_are_scoped_to_the_current_user(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 3));
        $user = User::factory()->create();
        $other = User::factory()->create();
        $household = Household::factory()->ownedBy($user)->create(['name' => 'Our household']);
        $foreign = Household::factory()->ownedBy($other)->create(['name' => 'Foreign household']);
        $personal = MealPlan::factory()->create(['created_by' => $user->id]);
        $shared = MealPlan::factory()->create(['created_by' => $user->id, 'household_id' => $household->id]);
        $foreignPlan = MealPlan::factory()->create(['created_by' => $other->id, 'household_id' => $foreign->id]);
        foreach ([$personal, $shared, $foreignPlan] as $plan) {
            ShoppingList::factory()->create(['meal_plan_id' => $plan->id]);
        }
        MealPlanEntry::factory()->create(['meal_plan_id' => $personal->id]);
        MealPlanEntry::factory()->create(['meal_plan_id' => $foreignPlan->id]);
        $invitation = HouseholdInvitation::factory()->create(['email' => mb_strtolower($user->email), 'household_id' => $household->id]);
        HouseholdInvitation::factory()->create(['email' => $user->email, 'expires_at' => now()->subDay()]);
        HouseholdInvitation::factory()->create(['email' => $other->email]);
        $this->actingAs($user)->get('/en/my-kitchen')->assertOk()->assertDontSee('Foreign household')
            ->assertViewHas('shopping', fn ($items) => $items->count() === 2)
            ->assertViewHas('invitations', fn ($items) => $items->modelKeys() === [$invitation->id])
            ->assertViewHas('planned', fn ($items) => $items->sum() === 1)
            ->assertViewHas('days', fn ($items) => $items->count() === 7)
            ->assertDontSee($invitation->token_hash);
        $household->members()->where('user_id', $user->id)->delete();
        $this->get('/en/my-kitchen')->assertOk()->assertViewHas('shopping', fn ($items) => $items->count() === 1);
    }

    public function test_removed_permissions_and_disabled_variations_hide_restricted_modules(): void
    {
        config(['recipe-variations.enabled' => false]);
        $user = User::factory()->create();
        $user->syncRoles([]);
        $this->actingAs($user)->get('/en/my-kitchen')->assertOk()->assertViewHas('canPlan', false)
            ->assertViewHas('counts', fn ($counts) => $counts['shared'] === null && $counts['originals'] === null && $counts['households'] === null)
            ->assertDontSee('id="dashboard-shared"', false)->assertDontSee('id="dashboard-originals"', false)
            ->assertDontSee('id="dashboard-households"', false)->assertDontSee('id="dashboard-shopping"', false);
    }

    public function test_dashboard_query_count_does_not_grow_with_saved_and_owned_recipes(): void
    {
        $user = User::factory()->create();
        $read = function () use ($user): int {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->actingAs($user)->get('/en/my-kitchen')->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        $recipe = Recipe::factory()->published()->create(['author_id' => $user->id]);
        $user->favoriteRecipes()->attach($recipe);
        $single = $read();
        for ($i = 0; $i < 12; $i++) {
            $recipe = Recipe::factory()->published()->create(['author_id' => $user->id]);
            $user->favoriteRecipes()->attach($recipe);
        }
        $this->assertLessThanOrEqual($single + 3, $read());
    }

    public function test_publication_states_and_latest_revision_are_displayed_without_loading_revision_payloads(): void
    {
        config(['recipe-variations.enabled' => true]);
        $user = User::factory()->contributor()->create();
        Recipe::factory()->create(['author_id' => $user->id]);
        Recipe::factory()->create(['author_id' => $user->id, 'status' => 'pending']);
        $rejected = Recipe::factory()->create(['author_id' => $user->id]);
        $rejected->forceFill(['rejection_reason' => 'Please clarify the steps.'])->save();
        $published = app(RecipePublishingService::class)->publish(Recipe::factory()->complete()->create(['author_id' => $user->id]));
        $this->actingAs($user)->get('/en/my-kitchen')->assertOk()
            ->assertViewHas('statusCounts', fn ($counts) => array_map('intval', $counts) === ['draft' => 1, 'in_review' => 1, 'published' => 1, 'rejected' => 1])
            ->assertViewHas('originals', fn ($items) => $items->firstWhere('id', $published->id)->latest_editor_status === 'published')
            ->assertSee('Latest revision: Published');
    }
}
