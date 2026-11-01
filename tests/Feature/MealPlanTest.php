<?php

namespace Tests\Feature;

use App\Enums\HouseholdRole;
use App\Enums\MealType;
use App\Exceptions\StaleMealPlanException;
use App\Livewire\MealPlanner;
use App\Models\Household;
use App\Models\MealPlan;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\User;
use App\Services\MealPlanService;
use App\Services\RecipeShareService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class MealPlanTest extends TestCase
{
    use RefreshDatabase;

    private function service(): MealPlanService
    {
        return app(MealPlanService::class);
    }

    private function monday(): CarbonImmutable
    {
        return MealPlan::weekStartFor(now());
    }

    private function recipe(string $title = 'Moussaka', string $slug = 'moussaka', array $attributes = [], bool $public = true): Recipe
    {
        $factory = $public ? Recipe::factory()->published() : Recipe::factory();
        $recipe = $factory->create($attributes);
        $recipe->fill(['en' => ['title' => $title, 'slug' => $slug, 'summary' => 'Tasty.'], 'el' => ['title' => $title.' EL', 'slug' => $slug.'-el', 'summary' => 'Νόστιμο.']])->save();

        return $recipe;
    }

    private function household(User $owner, array $members = []): Household
    {
        $household = Household::factory()->ownedBy($owner)->create();

        foreach ($members as [$user, $role]) {
            $household->members()->create(['user_id' => $user->id, 'role' => $role]);
        }

        return $household;
    }

    private function addDinner(User $actor, MealPlan $plan, Recipe $recipe, int $servings = 4): MealPlanEntry
    {
        return $this->service()->addEntry($actor, $plan, $recipe, $this->monday(), MealType::Dinner, $servings);
    }

    public function test_personal_plan_is_created_once_per_week_and_belongs_to_its_creator(): void
    {
        $user = User::factory()->create();

        $first = $this->service()->ensurePlan($user, null, now());
        $second = $this->service()->ensurePlan($user, null, $this->monday()->addDays(2));

        $this->assertTrue($first->is($second));
        $this->assertTrue($first->isPersonal());
        $this->assertSame($this->monday()->toDateString(), $first->week_start->toDateString());
    }

    public function test_database_enforces_monday_weeks_and_one_plan_per_scope(): void
    {
        $user = User::factory()->create();
        $this->service()->ensurePlan($user, null, now());

        try {
            DB::table('meal_plans')->insert(['created_by' => $user->id, 'week_start' => $this->monday()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
            $this->fail('Duplicate personal plan was stored.');
        } catch (QueryException) {
        }

        $this->expectException(QueryException::class);
        DB::table('meal_plans')->insert(['created_by' => $user->id, 'week_start' => $this->monday()->addDay()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_user_only_sees_and_edits_their_own_personal_plan(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $plan = $this->service()->ensurePlan($owner, null, now());

        $this->assertTrue($owner->can('view', $plan));
        $this->assertFalse($other->can('view', $plan));
        $this->assertFalse(User::factory()->admin()->create()->can('view', $plan));
        $this->assertNull($this->service()->findPlan($other, null, now()));

        $this->expectException(AuthorizationException::class);
        $this->addDinner($other, $plan, $this->recipe());
    }

    public function test_user_without_planner_permission_cannot_plan(): void
    {
        $user = User::factory()->unverified()->create();

        $this->expectException(HttpException::class);
        $this->service()->ensurePlan($user, null, now());
    }

    public function test_adding_an_entry_stores_recipe_meal_servings_and_author(): void
    {
        $user = User::factory()->create();
        $plan = $this->service()->ensurePlan($user, null, now());

        $entry = $this->service()->addEntry($user, $plan, $this->recipe(), $this->monday()->addDays(2), MealType::Lunch, 6, '  Leftovers  ');

        $this->assertSame(MealType::Lunch, $entry->meal_type);
        $this->assertSame(6, $entry->servings);
        $this->assertSame('Leftovers', $entry->notes);
        $this->assertSame($user->id, $entry->added_by);
        $this->assertSame(1, $entry->version);
    }

    public function test_entries_outside_the_week_or_with_invalid_servings_are_rejected(): void
    {
        $user = User::factory()->create();
        $plan = $this->service()->ensurePlan($user, null, now());
        $recipe = $this->recipe();

        foreach ([[$this->monday()->addDays(7), 4], [$this->monday()->subDay(), 4], [$this->monday(), 0], [$this->monday(), 100]] as [$date, $servings]) {
            try {
                $this->service()->addEntry($user, $plan, $recipe, $date, MealType::Dinner, $servings);
                $this->fail('Invalid entry accepted.');
            } catch (ValidationException) {
            }
        }

        $this->assertSame(0, $plan->entries()->count());
    }

    public function test_entry_limit_is_enforced(): void
    {
        config(['planner.max_entries_per_plan' => 1]);
        $user = User::factory()->create();
        $plan = $this->service()->ensurePlan($user, null, now());
        $this->addDinner($user, $plan, $this->recipe());

        $this->expectException(ValidationException::class);
        $this->addDinner($user, $plan, $this->recipe('Pastitsio', 'pastitsio'));
    }

    public function test_private_recipe_of_another_user_cannot_be_planned(): void
    {
        $author = User::factory()->contributor()->create();
        $other = User::factory()->create();
        $secret = $this->recipe('Secret', 'secret', ['author_id' => $author->id, 'visibility' => 'private'], public: false);
        $plan = $this->service()->ensurePlan($other, null, now());

        $this->expectException(ValidationException::class);
        $this->addDinner($other, $plan, $secret);
    }

    public function test_own_private_recipe_can_go_on_a_personal_plan(): void
    {
        $author = User::factory()->contributor()->create();
        $mine = $this->recipe('Mine', 'mine', ['author_id' => $author->id, 'visibility' => 'private'], public: false);
        $plan = $this->service()->ensurePlan($author, null, now());

        $this->assertSame($mine->id, $this->addDinner($author, $plan, $mine)->recipe_id);
    }

    public function test_private_recipe_cannot_join_a_shared_plan_unless_every_member_can_view_it(): void
    {
        $author = User::factory()->contributor()->create();
        $friend = User::factory()->create();
        $household = $this->household($author, [[$friend, HouseholdRole::Member]]);
        $plan = $this->service()->ensurePlan($author, $household, now());
        $mine = $this->recipe('Mine', 'mine', ['author_id' => $author->id, 'visibility' => 'private'], public: false);

        $this->assertFalse($this->service()->recipeAddable($author, $household, $mine));

        try {
            $this->addDinner($author, $plan, $mine);
            $this->fail('Private recipe was added to a shared plan.');
        } catch (ValidationException) {
            $this->assertSame(0, $plan->entries()->count());
        }
    }

    public function test_shared_recipe_is_addable_only_when_all_members_hold_active_shares(): void
    {
        Notification::fake();
        $author = User::factory()->contributor()->create();
        $friend = User::factory()->create();
        $outsider = User::factory()->create();
        $household = $this->household($author, [[$friend, HouseholdRole::Member]]);
        $recipe = $this->recipe('Shared', 'shared', ['author_id' => $author->id, 'visibility' => 'private'], public: false);

        app(RecipeShareService::class)->enableSharing($author, $recipe);
        $this->assertFalse($this->service()->recipeAddable($author, $household, $recipe->fresh()));

        app(RecipeShareService::class)->share($author, $recipe->fresh(), $friend->email, 'en');
        $this->assertTrue($this->service()->recipeAddable($author, $household, $recipe->fresh()));

        $household->members()->create(['user_id' => $outsider->id, 'role' => HouseholdRole::Viewer]);
        $this->assertFalse($this->service()->recipeAddable($author, $household, $recipe->fresh()));
    }

    public function test_public_recipes_are_always_addable_to_shared_plans(): void
    {
        $owner = User::factory()->create();
        $household = $this->household($owner, [[User::factory()->create(), HouseholdRole::Viewer]]);
        $plan = $this->service()->ensurePlan($owner, $household, now());

        $this->assertSame(1, $this->addDinner($owner, $plan, $this->recipe())->version);
    }

    public function test_entry_becomes_unavailable_without_leaking_when_access_is_revoked_but_is_retained(): void
    {
        Notification::fake();
        $author = User::factory()->contributor()->create();
        $friend = User::factory()->create();
        $household = $this->household($author, [[$friend, HouseholdRole::Member]]);
        $recipe = $this->recipe('Grandma Secret', 'grandma-secret', ['author_id' => $author->id, 'visibility' => 'private'], public: false);
        app(RecipeShareService::class)->enableSharing($author, $recipe);
        app(RecipeShareService::class)->share($author, $recipe->fresh(), $friend->email, 'en');

        $plan = $this->service()->ensurePlan($author, $household, now());
        $entry = $this->service()->addEntry($author, $plan, $recipe->fresh(), $this->monday(), MealType::Dinner, 4, 'Private joke note');
        $this->assertSame('Grandma Secret', $this->service()->weekView($friend, $plan, 'en')->flatten(2)->first()['title']);

        app(RecipeShareService::class)->stopSharing($author, $recipe->fresh());

        $item = $this->service()->weekView($friend, $plan, 'en')->flatten(2)->first();
        $this->assertFalse($item['available']);
        $this->assertNull($item['title']);
        $this->assertNull($item['url']);
        $this->assertNull($item['notes']);
        $this->assertDatabaseHas('meal_plan_entries', ['id' => $entry->id]);

        Livewire::actingAs($friend)->test(MealPlanner::class, ['locale' => 'en', 'householdId' => $household->id])
            ->assertSee('Recipe unavailable')->assertDontSee('Grandma Secret')->assertDontSee('Private joke note')->assertDontSee('grandma-secret');
    }

    public function test_member_who_cannot_see_a_recipe_may_remove_but_not_edit_its_entry(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $household = $this->household($owner, [[$member, HouseholdRole::Member]]);
        $plan = $this->service()->ensurePlan($owner, $household, now());
        $recipe = $this->recipe();
        $entry = $this->addDinner($owner, $plan, $recipe);
        $recipe->forceFill(['visibility' => 'private', 'status' => 'draft', 'published_at' => null])->saveQuietly();

        try {
            $this->service()->updateEntry($member, $entry->fresh(), 1, ['servings' => 2]);
            $this->fail('Member edited an unavailable recipe.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $this->service()->removeEntry($member, $entry->fresh(), 1);
        $this->assertDatabaseMissing('meal_plan_entries', ['id' => $entry->id]);
    }

    public function test_viewers_read_shared_plans_but_cannot_change_them(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $household = $this->household($owner, [[$viewer, HouseholdRole::Viewer]]);
        $plan = $this->service()->ensurePlan($owner, $household, now());
        $entry = $this->addDinner($owner, $plan, $this->recipe());

        $this->assertNotNull($this->service()->findPlan($viewer, $household, now()));
        $this->assertCount(1, $this->service()->weekView($viewer, $plan, 'en'));

        foreach ([fn () => $this->addDinner($viewer, $plan, $this->recipe('B', 'b')), fn () => $this->service()->updateEntry($viewer, $entry, 1, ['servings' => 2]), fn () => $this->service()->removeEntry($viewer, $entry, 1)] as $attempt) {
            try {
                $attempt();
                $this->fail('Viewer changed the plan.');
            } catch (AuthorizationException) {
            }
        }

        $this->assertDatabaseHas('meal_plan_entries', ['id' => $entry->id, 'servings' => 4]);
    }

    public function test_members_edit_shared_plans_and_outsiders_cannot_even_see_them(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $household = $this->household($owner, [[$member, HouseholdRole::Member]]);
        $plan = $this->service()->ensurePlan($member, $household, now());
        $this->addDinner($member, $plan, $this->recipe());
        $outsider = User::factory()->create();

        $this->assertFalse($outsider->can('view', $plan));
        $this->actingAs($outsider)->get(route('households.planner', ['locale' => 'en', 'household' => $household->id]))->assertNotFound();
        $this->actingAs(User::factory()->admin()->create())->get(route('households.planner', ['locale' => 'en', 'household' => $household->id]))->assertNotFound();
    }

    public function test_stale_update_is_rejected_and_loses_nothing(): void
    {
        $user = User::factory()->create();
        $plan = $this->service()->ensurePlan($user, null, now());
        $entry = $this->addDinner($user, $plan, $this->recipe());

        $this->service()->updateEntry($user, $entry, 1, ['servings' => 6]);

        try {
            $this->service()->updateEntry($user, $entry->fresh(), 1, ['servings' => 2]);
            $this->fail('Stale write succeeded.');
        } catch (StaleMealPlanException) {
        }

        $fresh = $entry->fresh();
        $this->assertSame(6, $fresh->servings);
        $this->assertSame(2, $fresh->version);
    }

    public function test_removing_a_changed_entry_conflicts_but_removing_a_gone_entry_is_quiet(): void
    {
        $user = User::factory()->create();
        $plan = $this->service()->ensurePlan($user, null, now());
        $entry = $this->addDinner($user, $plan, $this->recipe());
        $this->service()->updateEntry($user, $entry, 1, ['notes' => 'edited']);

        try {
            $this->service()->removeEntry($user, $entry, 1);
            $this->fail('Stale removal succeeded.');
        } catch (StaleMealPlanException) {
            $this->assertDatabaseHas('meal_plan_entries', ['id' => $entry->id]);
        }

        $this->service()->removeEntry($user, $entry, 2);
        $this->service()->removeEntry($user, $entry, 2);
        $this->assertDatabaseMissing('meal_plan_entries', ['id' => $entry->id]);
    }

    public function test_two_members_editing_the_same_entry_only_one_wins(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $household = $this->household($owner, [[$member, HouseholdRole::Member]]);
        $plan = $this->service()->ensurePlan($owner, $household, now());
        $entry = $this->addDinner($owner, $plan, $this->recipe());
        $outcomes = [];

        foreach ([[$owner, 5], [$member, 8]] as [$actor, $servings]) {
            try {
                $this->service()->updateEntry($actor, $entry, 1, ['servings' => $servings]);
                $outcomes[] = 'saved';
            } catch (StaleMealPlanException) {
                $outcomes[] = 'conflict';
            }
        }

        $this->assertSame(['saved', 'conflict'], $outcomes);
        $this->assertSame(5, $entry->fresh()->servings);
    }

    public function test_search_returns_only_recipes_every_household_member_can_view(): void
    {
        $author = User::factory()->contributor()->create();
        $friend = User::factory()->create();
        $household = $this->household($author, [[$friend, HouseholdRole::Member]]);
        $this->recipe('Lemon chicken', 'lemon-chicken');
        $this->recipe('Lemon secret', 'lemon-secret', ['author_id' => $author->id, 'visibility' => 'private'], public: false);

        $personal = $this->service()->searchRecipes($author, null, 'lemon', 'en')->map(fn ($r) => $r->displayTranslation('en')->title)->all();
        $shared = $this->service()->searchRecipes($author, $household, 'lemon', 'en')->map(fn ($r) => $r->displayTranslation('en')->title)->all();

        $this->assertEqualsCanonicalizing(['Lemon chicken', 'Lemon secret'], $personal);
        $this->assertSame(['Lemon chicken'], $shared);
        $this->assertSame([], $this->service()->searchRecipes($author, null, 'l', 'en')->all());
    }

    public function test_search_never_returns_other_peoples_private_recipes(): void
    {
        $author = User::factory()->contributor()->create();
        $this->recipe('Lemon secret', 'lemon-secret', ['author_id' => $author->id, 'visibility' => 'private'], public: false);

        $this->assertCount(0, $this->service()->searchRecipes(User::factory()->create(), null, 'lemon', 'en'));
    }

    public function test_planner_component_adds_edits_and_removes_entries(): void
    {
        $user = User::factory()->create();
        $recipe = $this->recipe('Spanakopita', 'spanakopita');

        $component = Livewire::actingAs($user)->test(MealPlanner::class, ['locale' => 'en'])
            ->set('search', 'spana')->assertSee('Spanakopita')
            ->call('selectRecipe', $recipe->id)
            ->set('date', $this->monday()->toDateString())->set('meal', 'lunch')->set('servings', 3)
            ->call('addEntry')->assertHasNoErrors();

        $entry = MealPlanEntry::query()->firstOrFail();
        $this->assertSame(MealType::Lunch, $entry->meal_type);

        $component->call('startEdit', $entry->id)->set('editServings', 5)->call('saveEdit')->assertHasNoErrors();
        $this->assertSame(5, $entry->fresh()->servings);

        $component->call('removeEntry', $entry->id, $entry->fresh()->version);
        $this->assertSame(0, MealPlanEntry::query()->count());
    }

    public function test_planner_component_shows_a_conflict_message_for_a_stale_edit(): void
    {
        $user = User::factory()->create();
        $plan = $this->service()->ensurePlan($user, null, now());
        $entry = $this->addDinner($user, $plan, $this->recipe());

        $component = Livewire::actingAs($user)->test(MealPlanner::class, ['locale' => 'en'])
            ->call('startEdit', $entry->id)->set('editServings', 9);

        $this->service()->updateEntry($user, $entry, 1, ['servings' => 2]);

        $component->call('saveEdit')->assertHasErrors('conflict');
        $this->assertSame(2, $entry->fresh()->servings);
    }

    public function test_week_navigation_moves_by_whole_weeks(): void
    {
        Livewire::actingAs(User::factory()->create())->test(MealPlanner::class, ['locale' => 'en'])
            ->call('nextWeek')->assertSet('week', $this->monday()->addWeek()->toDateString())
            ->call('currentWeek')->assertSet('week', $this->monday()->toDateString());
    }

    public function test_greek_planner_renders_translated_labels_and_recipe_titles(): void
    {
        $user = User::factory()->create();
        $plan = $this->service()->ensurePlan($user, null, now());
        $this->addDinner($user, $plan, $this->recipe());

        $this->actingAs($user)->get(route('planner.index', ['locale' => 'el']))
            ->assertOk()->assertSee('Το πρόγραμμα γευμάτων σας')->assertSee('Βραδινό')->assertSee('Moussaka EL');
    }

    public function test_every_english_planner_key_has_a_greek_translation(): void
    {
        $this->assertSame(array_keys(Arr::dot(require lang_path('en/planner.php'))), array_keys(Arr::dot(require lang_path('el/planner.php'))));
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('planner.index', ['locale' => 'en']))->assertRedirect('/login');
    }

    public function test_deleting_a_household_removes_its_plans_and_entries(): void
    {
        $owner = User::factory()->create();
        $household = $this->household($owner);
        $plan = $this->service()->ensurePlan($owner, $household, now());
        $this->addDinner($owner, $plan, $this->recipe());

        $household->delete();

        $this->assertSame(0, MealPlan::query()->count());
        $this->assertSame(0, MealPlanEntry::query()->count());
    }
}
