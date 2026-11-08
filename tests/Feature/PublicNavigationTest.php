<?php

namespace Tests\Feature;

use App\Livewire\PersonalRecipes;
use App\Models\Recipe;
use App\Models\User;
use App\Services\RecipePublishingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PublicNavigationTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['en', 'Discover'])]
    #[TestWith(['el', 'Ανακαλύψτε'])]
    public function test_guests_can_use_localized_navigation_without_private_or_dead_destinations(string $locale, string $discover): void
    {
        $this->get('/'.$locale)->assertOk()->assertSee('My Kitchen')->assertSee($discover)
            ->assertSee('href="/'.$locale.'/ingredients"', false)
            ->assertSee('href="/'.$locale.'#recipes"', false)
            ->assertSee('href="/login"', false)->assertSee('href="/register"', false)
            ->assertSee('id="mobile-navigation-panel"', false)
            ->assertDontSee('href="/admin', false)->assertDontSee('href="/'.$locale.'/my-kitchen"', false);
    }

    public function test_members_get_existing_kitchen_destinations_without_staff_access(): void
    {
        config(['recipe-variations.enabled' => true]);
        $member = User::factory()->create();

        $this->actingAs($member)->get('/en')->assertOk()
            ->assertSee('href="/en/saved-recipes"', false)
            ->assertSee('href="/en/shared-with-me"', false)
            ->assertSee('href="/en/my-recipes?filter=original"', false)
            ->assertSee('href="/en/my-recipes?filter=variation"', false)
            ->assertSee('href="/en/households"', false)
            ->assertSee('href="/en/planner/shopping"', false)
            ->assertSee('href="/en/contributor"', false)
            ->assertDontSee('href="/admin', false);
        $this->get('/admin/recipes/create')->assertForbidden();
    }

    public function test_contributor_navigation_does_not_grant_panel_access(): void
    {
        $contributor = User::factory()->contributor()->create();

        $response = $this->actingAs($contributor)->get('/en')->assertOk()->assertDontSee('href="/admin', false);
        $header = strstr($response->getContent(), '</header>', true);
        $this->assertStringNotContainsString('href="/en/contributor"', $header);
        $this->get('/admin/recipes/create')->assertForbidden();
    }

    public function test_staff_creation_link_uses_the_frontend_studio_and_keeps_admin_access(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/en')->assertOk()->assertSee('href="/en/recipe-studio/create"', false)
            ->assertSee('Create Recipe')->assertSee('href="/admin"', false);
    }

    public function test_unverified_users_are_directed_to_verification_instead_of_private_modules(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get('/en')->assertOk()->assertSee('href="/email/verify"', false)
            ->assertDontSee('href="/en/saved-recipes"', false)
            ->assertDontSee('href="/en/planner"', false)
            ->assertDontSee('href="/en/households"', false)
            ->assertSee('action="/logout"', false);
    }

    public function test_navigation_respects_removed_permissions_and_variation_feature_flag(): void
    {
        config(['recipe-variations.enabled' => false]);
        $user = User::factory()->create();
        $user->syncRoles([]);

        $this->actingAs($user)->get('/en')->assertOk()
            ->assertDontSee('href="/en/shared-with-me"', false)
            ->assertDontSee('href="/en/planner"', false)
            ->assertDontSee('href="/en/planner/shopping"', false)
            ->assertDontSee('href="/en/households"', false)
            ->assertDontSee('href="/en/my-recipes', false);
    }

    public function test_language_switch_retains_catalogue_filters(): void
    {
        $this->get('/en/ingredients?search=olive&group=vegetables')->assertOk()
            ->assertSee('href="'.route('ingredients.index', ['locale' => 'el']).'?search=olive&amp;group=vegetables"', false);
    }

    public function test_language_switch_keeps_the_current_shopping_page_and_week(): void
    {
        $member = User::factory()->create();

        $this->actingAs($member)->get('/en/planner/shopping?week=2026-11-02')->assertOk()
            ->assertSee('href="/el/planner/shopping?week=2026-11-02"', false);
    }

    public function test_recipe_language_switch_uses_the_translated_slug_and_retains_servings(): void
    {
        $recipe = Recipe::factory()->complete()->create();
        $recipe->categories()->first()->update([
            'en' => ['name' => 'Soups', 'slug' => 'soups'],
            'el' => ['name' => 'Σούπες', 'slug' => 'soupes'],
        ]);
        $this->actingAs(User::factory()->admin()->create());
        app(RecipePublishingService::class)->publish($recipe);
        $recipe->refresh();
        $greekSlug = $recipe->getTranslation('el', false)->slug;

        $this->get('/en/recipes/'.$recipe->getTranslation('en', false)->slug.'?servings=6')->assertOk()
            ->assertSee('href="'.route('recipes.show', ['locale' => 'el', 'slug' => $greekSlug]).'?servings=6"', false);
    }

    public function test_personal_recipe_links_initialize_the_requested_filter_without_exposing_other_owners(): void
    {
        config(['recipe-variations.enabled' => true]);
        $owner = User::factory()->create();
        $own = Recipe::factory()->complete()->create(['author_id' => $owner->id]);
        $foreign = Recipe::factory()->complete()->create();

        Livewire::actingAs($owner)->withQueryParams(['filter' => 'original'])
            ->test(PersonalRecipes::class, ['locale' => 'en'])->assertSet('filter', 'original')
            ->assertViewHas('recipes', fn ($recipes) => $recipes->pluck('id')->all() === [$own->id])
            ->assertDontSee($foreign->getTranslation('en', false)->title);
    }

    public function test_account_names_are_escaped_in_desktop_and_mobile_navigation(): void
    {
        $member = User::factory()->create(['name' => '<script>alert("name")</script>']);

        $this->actingAs($member)->get('/en')->assertOk()
            ->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert("name")</script>', false);
    }

    #[TestWith(['en'])]
    #[TestWith(['el'])]
    public function test_kitchen_shortcuts_reuse_navigation_permissions_and_feature_flags(string $locale): void
    {
        config(['recipe-variations.enabled' => false]);
        $member = User::factory()->create();
        $member->syncRoles([]);
        $this->actingAs($member);
        app()->setLocale($locale);

        $this->blade('<x-site.kitchen-shortcuts />')
            ->assertSee('href="/'.$locale.'/saved-recipes"', false)
            ->assertDontSee('/'.$locale.'/shared-with-me', false)
            ->assertDontSee('/'.$locale.'/my-recipes', false)
            ->assertDontSee('/'.$locale.'/planner', false)
            ->assertDontSee('/'.$locale.'/households', false);
    }
}
