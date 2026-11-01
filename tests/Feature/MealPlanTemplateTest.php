<?php

namespace Tests\Feature;

use App\Enums\MealType;
use App\Exceptions\StaleTemplateException;
use App\Livewire\MealPlanTemplates;
use App\Models\Household;
use App\Models\MealPlan;
use App\Models\MealPlanTemplate;
use App\Models\MealPlanTemplateEntry;
use App\Models\Recipe;
use App\Models\User;
use App\Services\MealPlanService;
use App\Services\MealPlanTemplateService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class MealPlanTemplateTest extends TestCase
{
    use RefreshDatabase;

    private function service(): MealPlanTemplateService
    {
        return app(MealPlanTemplateService::class);
    }

    private function monday(int $weeksAhead = 0): CarbonImmutable
    {
        return MealPlan::weekStartFor(now())->addWeeks($weeksAhead);
    }

    private function recipe(string $title = 'Moussaka', bool $public = true, ?User $author = null): Recipe
    {
        $recipe = $public ? Recipe::factory()->published()->create() : Recipe::factory()->create(['author_id' => $author?->id, 'visibility' => 'private']);
        $slug = str($title)->slug()->toString().'-'.$recipe->id;
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

    private function plan(User $owner, ?Household $household = null, int $weeksAhead = 0): MealPlan
    {
        return MealPlan::factory()->create(['created_by' => $owner->id, 'household_id' => $household?->id, 'week_start' => $this->monday($weeksAhead)->toDateString()]);
    }

    private function addTo(MealPlan $plan, Recipe $recipe, int $dayOffset, string $meal = 'dinner', int $servings = 4): void
    {
        $plan->entries()->create(['recipe_id' => $recipe->id, 'planned_on' => $plan->week_start->addDays($dayOffset)->toDateString(), 'meal_type' => $meal, 'servings' => $servings]);
    }

    public function test_saving_a_week_stores_weekdays_meals_and_servings_not_dates(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user);
        $this->addTo($plan, $this->recipe('A'), 0, 'dinner', 3);
        $this->addTo($plan, $this->recipe('B'), 6, 'breakfast', 2);

        $result = $this->service()->saveFromPlan($user, $plan, '  Busy week ');

        $this->assertSame('Busy week', $result['template']->name);
        $this->assertSame(0, $result['skipped']);
        $this->assertTrue($result['template']->isPersonal());
        $rows = $result['template']->entries()->orderBy('day_of_week')->get();
        $this->assertSame([1, 7], $rows->pluck('day_of_week')->all());
        $this->assertSame([MealType::Dinner, MealType::Breakfast], $rows->pluck('meal_type')->all());
        $this->assertSame([3, 2], $rows->pluck('servings')->all());
    }

    public function test_applying_places_meals_on_the_right_days_of_another_week(): void
    {
        $user = User::factory()->create();
        $source = $this->plan($user);
        $recipe = $this->recipe();
        $this->addTo($source, $recipe, 2, 'lunch', 5);
        $template = $this->service()->saveFromPlan($user, $source, 'T')['template'];
        $target = $this->plan($user, null, 3);

        $counts = $this->service()->apply($user, $template, $target);

        $this->assertSame(1, $counts['added']);
        $entry = $target->entries()->first();
        $this->assertSame($target->week_start->addDays(2)->toDateString(), $entry->planned_on->toDateString());
        $this->assertSame([MealType::Lunch, 5, $recipe->id], [$entry->meal_type, $entry->servings, $entry->recipe_id]);
    }

    public function test_applying_twice_is_idempotent_and_keeps_existing_entries(): void
    {
        $user = User::factory()->create();
        $source = $this->plan($user);
        $this->addTo($source, $this->recipe('A'), 0);
        $this->addTo($source, $this->recipe('B'), 1);
        $template = $this->service()->saveFromPlan($user, $source, 'T')['template'];
        $target = $this->plan($user, null, 1);
        $this->addTo($target, $this->recipe('Mine'), 4);

        $first = $this->service()->apply($user, $template, $target);
        $second = $this->service()->apply($user, $template, $target);

        $this->assertSame(2, $first['added']);
        $this->assertSame(0, $second['added']);
        $this->assertSame(2, $second['duplicates']);
        $this->assertSame(3, $target->entries()->count());
    }

    public function test_saving_into_a_household_template_leaves_out_recipes_not_every_member_can_view(): void
    {
        $owner = User::factory()->create();
        $friend = User::factory()->create();
        $household = $this->household($owner, [[$friend, 'member']]);
        $plan = $this->plan($owner, $household);
        $this->addTo($plan, $this->recipe('Public'), 0);
        $private = $this->recipe('Secret', false, $owner);
        // Bypass the planner so the entry exists even though the rule would normally block it.
        $this->addTo($plan, $private, 1);

        $result = $this->service()->saveFromPlan($owner, $plan, 'Family');

        $this->assertSame(1, $result['skipped']);
        $this->assertSame(1, $result['template']->entries()->count());
        $this->assertFalse($result['template']->isPersonal());
    }

    public function test_applying_rechecks_access_so_revoked_recipes_are_skipped(): void
    {
        $owner = User::factory()->create();
        $household = $this->household($owner);
        $private = $this->recipe('Mine', false, $owner);
        $template = MealPlanTemplate::factory()->create(['household_id' => $household->id, 'created_by' => $owner->id]);
        MealPlanTemplateEntry::factory()->create(['meal_plan_template_id' => $template->id, 'recipe_id' => $private->id]);

        // Later a member joins who cannot view the private recipe.
        $household->members()->create(['user_id' => User::factory()->create()->id, 'role' => 'member']);
        $target = $this->plan($owner, $household, 1);

        $counts = $this->service()->apply($owner, $template, $target);

        $this->assertSame(['added' => 0, 'duplicates' => 0, 'unavailable' => 1, 'over_limit' => 0], $counts);
        $this->assertSame(0, $target->entries()->count());
    }

    public function test_unavailable_template_recipes_show_no_title_to_those_who_cannot_view_them(): void
    {
        $owner = User::factory()->create();
        $friend = User::factory()->create();
        $household = $this->household($owner, [[$friend, 'member']]);
        $template = MealPlanTemplate::factory()->create(['household_id' => $household->id, 'created_by' => $owner->id]);
        MealPlanTemplateEntry::factory()->create(['meal_plan_template_id' => $template->id, 'recipe_id' => $this->recipe('Hidden dish', false, $owner)->id]);

        $forOwner = $this->service()->entriesView($owner, $template, 'en')->first();
        $forFriend = $this->service()->entriesView($friend, $template, 'en')->first();

        $this->assertSame('Hidden dish', $forOwner['title']);
        $this->assertFalse($forFriend['available']);
        $this->assertNull($forFriend['title']);
    }

    public function test_template_authorization_follows_scope_and_household_roles(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $viewer = User::factory()->create();
        $outsider = User::factory()->create();
        $household = $this->household($owner, [[$member, 'member'], [$viewer, 'viewer']]);
        $shared = MealPlanTemplate::factory()->create(['household_id' => $household->id, 'created_by' => $owner->id]);
        $personal = MealPlanTemplate::factory()->create(['created_by' => $owner->id]);

        $this->assertTrue($viewer->can('view', $shared));
        $this->assertFalse($viewer->can('update', $shared));
        $this->assertTrue($member->can('update', $shared));
        $this->assertFalse($outsider->can('view', $shared));
        $this->assertFalse($member->can('view', $personal));
        $this->assertFalse(User::factory()->admin()->create()->can('view', $personal));
        $this->assertFalse(User::factory()->admin()->create()->can('view', $shared));
    }

    public function test_viewers_cannot_save_apply_rename_or_delete(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $household = $this->household($owner, [[$viewer, 'viewer']]);
        $plan = $this->plan($owner, $household);
        $this->addTo($plan, $this->recipe(), 0);
        $template = MealPlanTemplate::factory()->create(['household_id' => $household->id, 'created_by' => $owner->id]);

        try {
            $this->service()->saveFromPlan($viewer, $plan, 'X');
            $this->fail('Viewer saved a template.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        foreach ([
            fn () => $this->service()->apply($viewer, $template, $plan),
            fn () => $this->service()->rename($viewer, $template, 1, 'New'),
            fn () => $this->service()->delete($viewer, $template, 1),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A viewer changed a template.');
            } catch (AuthorizationException) {
            }
        }

        $this->assertSame(1, $template->fresh()->version);
    }

    public function test_outsiders_cannot_list_or_use_another_scope(): void
    {
        $owner = User::factory()->create();
        $household = $this->household($owner);

        $this->expectException(HttpException::class);
        $this->service()->list(User::factory()->create(), $household);
    }

    public function test_personal_templates_are_never_listed_for_other_users(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        MealPlanTemplate::factory()->create(['created_by' => $owner->id, 'name' => 'Private']);

        $this->assertCount(1, $this->service()->list($owner, null));
        $this->assertCount(0, $this->service()->list($other, null));
    }

    public function test_cannot_apply_a_template_to_someone_elses_plan(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $template = MealPlanTemplate::factory()->create(['created_by' => $owner->id]);

        $this->expectException(AuthorizationException::class);
        $this->service()->apply($owner, $template, $this->plan($other));
    }

    public function test_rename_and_delete_are_version_checked(): void
    {
        $user = User::factory()->create();
        $template = MealPlanTemplate::factory()->create(['created_by' => $user->id, 'name' => 'Old']);

        $this->service()->rename($user, $template, 1, 'New');
        $this->assertSame(['New', 2], [$template->fresh()->name, $template->fresh()->version]);

        try {
            $this->service()->rename($user, $template, 1, 'Stale');
            $this->fail('Stale rename accepted.');
        } catch (StaleTemplateException) {
        }

        try {
            $this->service()->delete($user, $template, 1);
            $this->fail('Stale delete accepted.');
        } catch (StaleTemplateException) {
        }

        $this->service()->delete($user, $template, 2);
        $this->service()->delete($user, $template, 2);
        $this->assertNull(MealPlanTemplate::find($template->id));
    }

    public function test_name_limit_and_empty_weeks_are_rejected(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user);

        foreach (['', str_repeat('x', 101)] as $name) {
            try {
                $this->service()->saveFromPlan($user, $plan, $name);
                $this->fail('Invalid name accepted.');
            } catch (ValidationException) {
            }
        }

        $this->expectException(ValidationException::class);
        $this->service()->saveFromPlan($user, $plan, 'Valid but empty');
    }

    public function test_template_limit_per_scope_is_enforced(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user);
        $this->addTo($plan, $this->recipe(), 0);
        config(['planner.max_templates_per_scope' => 1]);

        $this->service()->saveFromPlan($user, $plan, 'One');

        $this->expectException(ValidationException::class);
        $this->service()->saveFromPlan($user, $plan, 'Two');
    }

    public function test_entry_limit_is_respected_when_applying(): void
    {
        $user = User::factory()->create();
        $source = $this->plan($user);
        $this->addTo($source, $this->recipe('A'), 0);
        $this->addTo($source, $this->recipe('B'), 1);
        $template = $this->service()->saveFromPlan($user, $source, 'T')['template'];
        $target = $this->plan($user, null, 1);
        config(['planner.max_entries_per_plan' => 1]);

        $counts = $this->service()->apply($user, $template, $target);

        $this->assertSame(1, $counts['added']);
        $this->assertSame(1, $counts['over_limit']);
    }

    public function test_deleting_a_template_leaves_applied_meals_alone_and_cascades_its_entries(): void
    {
        $user = User::factory()->create();
        $source = $this->plan($user);
        $this->addTo($source, $this->recipe(), 0);
        $template = $this->service()->saveFromPlan($user, $source, 'T')['template'];
        $target = $this->plan($user, null, 1);
        $this->service()->apply($user, $template, $target);

        $this->service()->delete($user, $template, 1);

        $this->assertSame(0, MealPlanTemplateEntry::count());
        $this->assertSame(1, $target->entries()->count());
    }

    public function test_database_enforces_weekday_meal_and_unique_slots(): void
    {
        $template = MealPlanTemplate::factory()->create();
        $recipe = Recipe::factory()->create();
        $insert = fn (array $values) => DB::transaction(fn () => DB::table('meal_plan_template_entries')->insert(['meal_plan_template_id' => $template->id, 'recipe_id' => $recipe->id, 'day_of_week' => 1, 'meal_type' => 'dinner', 'servings' => 2, 'created_at' => now(), 'updated_at' => now(), ...$values]));

        foreach ([['day_of_week' => 8], ['meal_type' => 'brunch'], ['servings' => 0]] as $bad) {
            try {
                $insert($bad);
                $this->fail('Invalid row stored.');
            } catch (QueryException) {
            }
        }

        $insert([]);
        $this->expectException(QueryException::class);
        $insert([]);
    }

    public function test_page_saves_applies_and_renames_in_both_languages(): void
    {
        $user = User::factory()->create();
        $plan = app(MealPlanService::class)->ensurePlan($user, null, now());
        $this->addTo($plan, $this->recipe('Moussaka'), 0);

        $component = Livewire::actingAs($user)->test(MealPlanTemplates::class, ['locale' => 'en'])
            ->set('templateName', 'Favourite')
            ->call('saveCurrentWeek')
            ->assertSee('Favourite');

        $template = MealPlanTemplate::first();
        $component->call('toggle', $template->id)->assertSee('Moussaka')
            ->call('startRename', $template->id)->set('renameValue', 'Renamed')->call('saveRename')
            ->assertSee('Renamed')
            ->call('apply', $template->id)->assertSee('already');
        $this->assertSame('Renamed', $template->fresh()->name);

        Livewire::actingAs($user)->test(MealPlanTemplates::class, ['locale' => 'el'])->assertSee('Πρότυπα προγράμματος γευμάτων');
    }

    public function test_page_cannot_touch_templates_from_another_scope(): void
    {
        $user = User::factory()->create();
        $foreign = MealPlanTemplate::factory()->create();

        Livewire::actingAs($user)->test(MealPlanTemplates::class, ['locale' => 'en'])
            ->call('apply', $foreign->id)->assertHasErrors('conflict')
            ->call('remove', $foreign->id, 1);

        $this->assertNotNull($foreign->fresh());
    }

    public function test_routes_hide_households_from_outsiders_and_guests(): void
    {
        $owner = User::factory()->create();
        $household = $this->household($owner);

        $this->get(route('planner.templates', ['locale' => 'en']))->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get(route('households.templates', ['locale' => 'en', 'household' => $household->id]))->assertNotFound();
        $this->actingAs($owner)->get(route('households.templates', ['locale' => 'el', 'household' => $household->id]))->assertOk()->assertSee('Πρότυπα');
    }

    public function test_every_english_planner_key_has_a_greek_translation(): void
    {
        $this->assertSame(array_keys(Arr::dot(require lang_path('en/planner.php'))), array_keys(Arr::dot(require lang_path('el/planner.php'))));
    }
}
