<?php

namespace Tests\Feature;

use App\Livewire\KitchenPreview;
use App\Livewire\RecipeIndex;
use App\Models\Household;
use App\Models\MealPlan;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\RecipeRating;
use App\Models\RecipeShare;
use App\Models\ShoppingList;
use App\Models\Tag;
use App\Models\User;
use App\Services\RecipePublishingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class HomepageTest extends TestCase
{
    use RefreshDatabase;

    private function published(string $title = 'Lemon Sunday', array $attributes = []): Recipe
    {
        $recipe = Recipe::factory()->complete()->create($attributes);
        $recipe->translateOrNew('en')->title = $title;
        $recipe->save();
        foreach ($recipe->categories as $category) {
            $category->update(['en' => ['name' => 'Soups', 'slug' => 'soups-'.$category->id], 'el' => ['name' => 'Σούπες', 'slug' => 'soupes-'.$category->id]]);
        }

        return app(RecipePublishingService::class)->publish($recipe)->fresh();
    }

    #[TestWith(['en', 'What would you like to cook today?'])]
    #[TestWith(['el', 'Τι θα ήθελες να μαγειρέψεις σήμερα;'])]
    public function test_empty_homepage_has_localized_search_and_working_discovery_actions(string $locale, string $heading): void
    {
        $response = $this->get('/'.$locale)->assertOk()->assertSee($heading)->assertSee('id="recipes"', false)
            ->assertSee('name="q"', false)->assertSee('role="combobox"', false)
            ->assertSee('href="/'.$locale.'/ingredients"', false)
            ->assertDontSee('livewire.kitchen-preview')->assertHeader('Cache-Control');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertDatabaseCount('meal_plans', 0);
        $this->assertDatabaseCount('shopping_lists', 0);
    }

    public function test_featured_and_recent_sections_only_contain_public_recipes_and_are_bounded(): void
    {
        $featured = $this->published('Editorial favourite', ['is_featured' => true]);
        for ($i = 0; $i < 7; $i++) {
            $this->published('Public recipe '.$i);
        }
        $private = Recipe::factory()->complete()->create();
        $private->translateOrNew('en')->title = 'Never show this private recipe';
        $private->save();
        $future = $this->published('Future recipe');
        $future->update(['published_at' => now()->addDay()]);

        $this->get('/en')->assertOk()->assertSee('Editorial favourite')->assertDontSee('Never show this private recipe')->assertDontSee('Future recipe')
            ->assertViewHas('featured', fn ($recipes) => $recipes->count() === 6 && $recipes->first()->id === $featured->id)
            ->assertViewHas('recent', fn ($recipes) => $recipes->count() === 2)
            ->assertViewHas('quick', fn ($recipes) => $recipes->count() <= 3);
    }

    public function test_homepage_cards_show_actual_active_ratings_and_the_existing_save_action(): void
    {
        $recipe = $this->published();
        $user = User::factory()->create();
        $rating = new RecipeRating;
        $rating->forceFill(['recipe_id' => $recipe->id, 'user_id' => $user->id, 'rating' => 4, 'rating_status' => 'active', 'review_status' => 'pending'])->save();
        $user->favoriteRecipes()->attach($recipe);

        $this->actingAs($user)->get('/en')->assertOk()->assertSee('4.0')
            ->assertSee('action="/en/recipes/'.$recipe->id.'/save"', false)->assertSee('aria-pressed="true"', false);
        $this->post('/en/recipes/'.$recipe->id.'/save')->assertRedirect();
        $this->assertSame(0, $user->favoriteRecipes()->count());
    }

    public function test_unapproved_dietary_tags_are_not_homepage_shortcuts(): void
    {
        $recipe = $this->published();
        $tag = Tag::factory()->create(['code' => 'unverified-vegan', 'classification' => 'diet_style', 'is_public_enabled' => true]);
        $recipe->tags()->attach($tag);

        $this->get('/en')->assertOk()->assertDontSee('tag=unverified-vegan', false);
    }

    public function test_personal_preview_counts_only_the_current_users_accessible_resources(): void
    {
        config(['recipe-variations.enabled' => true]);
        $this->travelTo(now()->setDate(2026, 11, 3));
        $member = User::factory()->create();
        $other = User::factory()->create();
        $public = $this->published();
        Recipe::factory()->create(['author_id' => $member->id]);
        Recipe::factory()->create(['author_id' => $other->id]);
        $member->favoriteRecipes()->attach($public);
        $shared = Recipe::factory()->create(['visibility' => 'shared', 'author_id' => $other->id]);
        RecipeShare::create(['recipe_id' => $shared->id, 'user_id' => $member->id, 'shared_by' => $other->id, 'permission' => 'view']);
        $revoked = Recipe::factory()->create(['visibility' => 'shared', 'author_id' => $other->id]);
        RecipeShare::create(['recipe_id' => $revoked->id, 'user_id' => $member->id, 'shared_by' => $other->id, 'permission' => 'view', 'revoked_at' => now()]);
        $household = Household::factory()->ownedBy($member)->create();
        $personal = MealPlan::factory()->create(['created_by' => $member->id]);
        $foreign = MealPlan::factory()->create(['created_by' => $other->id]);
        $householdPlan = MealPlan::factory()->create(['household_id' => $household->id, 'created_by' => $member->id]);
        ShoppingList::factory()->create(['meal_plan_id' => $personal->id]);
        ShoppingList::factory()->create(['meal_plan_id' => $foreign->id]);
        ShoppingList::factory()->create(['meal_plan_id' => $householdPlan->id]);
        MealPlanEntry::factory()->create(['meal_plan_id' => $personal->id, 'recipe_id' => $public->id]);
        MealPlanEntry::factory()->create(['meal_plan_id' => $foreign->id, 'recipe_id' => $public->id]);

        Livewire::actingAs($member)->test(KitchenPreview::class, ['locale' => 'en'])
            ->assertViewHas('counts', fn ($counts) => $counts === ['saved' => 1, 'shared' => 1, 'originals' => 1, 'variations' => 0, 'households' => 1, 'shopping' => 2])
            ->assertViewHas('days', fn ($days) => $days->count() === 7)
            ->assertViewHas('planned', fn ($planned) => $planned->sum() === 1);
    }

    public function test_preview_rejects_guests_and_unverified_users(): void
    {
        Livewire::test(KitchenPreview::class, ['locale' => 'en'])->assertForbidden();
        Livewire::actingAs(User::factory()->unverified()->create())->test(KitchenPreview::class, ['locale' => 'en'])->assertForbidden();
    }

    public function test_missing_permissions_hide_shared_and_planner_counts_without_creating_records(): void
    {
        $user = User::factory()->create();
        $user->syncRoles([]);

        Livewire::actingAs($user)->test(KitchenPreview::class, ['locale' => 'el'])
            ->assertViewHas('counts', fn ($counts) => $counts['shared'] === null && $counts['shopping'] === null && $counts['households'] === null)
            ->assertViewHas('canPlan', false);
        $this->assertDatabaseCount('meal_plans', 0);
        $this->assertDatabaseCount('shopping_lists', 0);
    }

    public function test_catalogue_pagination_is_bounded_and_keeps_later_recipes_reachable(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->published('Dinner '.$i);
        }

        Livewire::test(RecipeIndex::class, ['locale' => 'en', 'showHero' => false])
            ->assertViewHas('recipes', fn ($recipes) => $recipes->count() === 9 && $recipes->total() === 10)
            ->call('setPage', 2, 'recipes_page')->assertViewHas('recipes', fn ($recipes) => $recipes->currentPage() === 2 && $recipes->count() === 1)
            ->set('search', 'Dinner')->assertViewHas('recipes', fn ($recipes) => $recipes->currentPage() === 1);
    }

    public function test_homepage_media_snapshots_are_loaded_in_batches(): void
    {
        $this->published('First dish');
        $this->published('Second dish');
        $pointLookups = [];
        DB::listen(function ($query) use (&$pointLookups): void {
            if (str_contains($query->sql, 'from "recipe_revisions"') && str_contains($query->sql, '"id" = ?')) {
                $pointLookups[] = $query->sql;
            }
        });

        $this->get('/en')->assertOk();

        $this->assertSame([], $pointLookups);
    }

    public function test_invalid_homepage_search_is_rejected_before_rendering(): void
    {
        $this->getJson('/en?q[]=olive')->assertUnprocessable()->assertJsonValidationErrors(['q']);
    }
}
