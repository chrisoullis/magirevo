<?php

namespace Tests\Feature;

use App\Enums\MealType;
use App\Exceptions\StaleBatchCookException;
use App\Exceptions\StaleMealPlanException;
use App\Livewire\BatchCooking;
use App\Models\BatchCook;
use App\Models\BatchCookMovement;
use App\Models\Household;
use App\Models\Ingredient;
use App\Models\MealPlan;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\Unit;
use App\Models\User;
use App\Services\BatchCookService;
use App\Services\MealPlanService;
use App\Services\ShoppingListService;
use Database\Seeders\AdditionalUnitSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class BatchCookingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([UnitSeeder::class, AdditionalUnitSeeder::class]);
    }

    private function service(): BatchCookService
    {
        return app(BatchCookService::class);
    }

    private function recipe(string $title = 'Stew', bool $public = true, ?User $author = null): Recipe
    {
        $recipe = $public ? Recipe::factory()->published()->create(['base_servings' => 2]) : Recipe::factory()->create(['base_servings' => 2, 'author_id' => $author?->id, 'visibility' => 'private']);
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

    private function batch(User $user, ?Household $household = null, ?Recipe $recipe = null, int $portions = 6): BatchCook
    {
        return $this->service()->create($user, $household, $recipe ?? $this->recipe(), $portions, now()->toDateString());
    }

    private function leftover(User $user, BatchCook $batch, int $portions = 2, int $dayOffset = 1): MealPlanEntry
    {
        return $this->service()->scheduleLeftover($user, $batch, now()->addDays($dayOffset)->toDateString(), MealType::Lunch, $portions);
    }

    private function assertLedgerMatches(BatchCook $batch): void
    {
        $batch->refresh();
        $this->assertSame(
            ['prepared' => $batch->prepared_portions, 'reserved' => $batch->reserved_portions, 'consumed' => $batch->consumed_portions],
            $this->service()->reconcile($batch),
        );
    }

    public function test_creating_a_batch_records_a_prepared_movement(): void
    {
        $user = User::factory()->create();
        $batch = $this->batch($user);

        $this->assertSame(6, $batch->prepared_portions);
        $this->assertSame(6, $batch->availablePortions());
        $this->assertSame(1, $batch->movements()->count());
        $this->assertLedgerMatches($batch);
    }

    public function test_balances_always_reconcile_with_the_ledger(): void
    {
        $user = User::factory()->create();
        $batch = $this->batch($user, null, null, 10);
        $first = $this->leftover($user, $batch, 3);
        $second = $this->leftover($user, $batch, 2, 2);
        $this->service()->consumeLeftover($user, $first);
        $this->service()->removeLeftover($user, $second->fresh(), $second->version);
        $this->service()->consume($user, $batch, 4, 'k1');

        $batch->refresh();
        $this->assertSame([10, 0, 7], [$batch->prepared_portions, $batch->reserved_portions, $batch->consumed_portions]);
        $this->assertSame(3, $batch->availablePortions());
        $this->assertLedgerMatches($batch);
    }

    public function test_cannot_reserve_more_than_the_free_portions(): void
    {
        $user = User::factory()->create();
        $batch = $this->batch($user, null, null, 4);
        $this->leftover($user, $batch, 3);

        $this->expectException(ValidationException::class);
        $this->leftover($user, $batch, 2, 2);
    }

    public function test_free_consumption_cannot_eat_reserved_portions(): void
    {
        $user = User::factory()->create();
        $batch = $this->batch($user, null, null, 4);
        $this->leftover($user, $batch, 3);

        try {
            $this->service()->consume($user, $batch, 2, 'k');
            $this->fail('Reserved portions were eaten.');
        } catch (ValidationException) {
            $this->assertSame(0, $batch->fresh()->consumed_portions);
        }

        $this->service()->consume($user, $batch, 1, 'k2');
        $this->assertSame(1, $batch->fresh()->consumed_portions);
    }

    public function test_consume_is_idempotent_for_a_repeated_key(): void
    {
        $user = User::factory()->create();
        $batch = $this->batch($user);

        $first = $this->service()->consume($user, $batch, 2, 'same');
        $again = $this->service()->consume($user, $batch, 2, 'same');

        $this->assertTrue($first->is($again));
        $this->assertSame(2, $batch->fresh()->consumed_portions);
        $this->assertLedgerMatches($batch);
    }

    public function test_a_leftover_cannot_be_consumed_twice(): void
    {
        $user = User::factory()->create();
        $batch = $this->batch($user);
        $entry = $this->leftover($user, $batch, 2);

        $this->assertTrue($this->service()->consumeLeftover($user, $entry));
        $this->assertFalse($this->service()->consumeLeftover($user, $entry->fresh()));
        $this->assertFalse($this->service()->consumeLeftover($user, $entry));

        $batch->refresh();
        $this->assertSame([0, 2], [$batch->reserved_portions, $batch->consumed_portions]);
        $this->assertLedgerMatches($batch);
    }

    public function test_two_members_marking_the_same_leftover_eaten_consume_once(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $household = $this->household($owner, [[$member, 'member']]);
        $batch = $this->batch($owner, $household);
        $entry = $this->leftover($owner, $batch, 2);

        $this->service()->consumeLeftover($owner, $entry);
        $this->service()->consumeLeftover($member, $entry->fresh());

        $this->assertSame(2, $batch->fresh()->consumed_portions);
        $this->assertSame(1, BatchCookMovement::where('type', 'consumed')->count());
    }

    public function test_database_rejects_overdrawn_balances_and_duplicate_keys(): void
    {
        $batch = $this->batch(User::factory()->create(), null, null, 4);

        try {
            $batch->forceFill(['reserved_portions' => 3, 'consumed_portions' => 2])->save();
            $this->fail('Balance check not enforced.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $this->expectException(QueryException::class);
        $batch->movements()->create(['type' => 'prepared', 'portions' => 1, 'idempotency_key' => 'prepared']);
    }

    public function test_leftover_cannot_be_scheduled_before_cooking_day(): void
    {
        $user = User::factory()->create();
        $batch = $this->service()->create($user, null, $this->recipe(), 4, now()->addDays(2)->toDateString());

        $this->expectException(ValidationException::class);
        $this->service()->scheduleLeftover($user, $batch, now()->addDay()->toDateString(), MealType::Lunch, 1);
    }

    public function test_moving_a_leftover_before_cooking_day_is_rejected(): void
    {
        $user = User::factory()->create();
        $batch = $this->service()->create($user, null, $this->recipe(), 4, now()->addDay()->toDateString());
        $entry = $this->service()->scheduleLeftover($user, $batch, now()->addDays(2)->toDateString(), MealType::Lunch, 1);

        $this->expectException(ValidationException::class);
        app(MealPlanService::class)->updateEntry($user, $entry, $entry->version, ['planned_on' => now()->toDateString()]);
    }

    public function test_leftovers_are_not_double_counted_in_the_shopping_list(): void
    {
        $user = User::factory()->create();
        $flour = Ingredient::factory()->create();
        $recipe = $this->recipe();
        RecipeIngredient::factory()->create(['recipe_id' => $recipe->id, 'ingredient_id' => $flour->id, 'unit_id' => Unit::where('code', 'g')->first()->id, 'quantity' => '100']);

        $plan = MealPlan::factory()->create(['created_by' => $user->id, 'week_start' => MealPlan::weekStartFor(now())->toDateString()]);
        $cooking = MealPlanEntry::factory()->create(['meal_plan_id' => $plan->id, 'recipe_id' => $recipe->id, 'servings' => 6, 'meal_type' => 'dinner', 'planned_on' => now()->toDateString()]);
        $batch = $this->service()->createFromEntry($user, $cooking);
        $this->leftover($user, $batch, 3, 0);

        $shopping = app(ShoppingListService::class);
        $list = $shopping->ensureList($user, $plan);
        $shopping->generate($user, $list);

        $item = $list->fresh()->items()->where('ingredient_id', $flour->id)->first();
        $this->assertEquals(300, $item->quantity);
        $this->assertSame(2, $plan->entries()->count());
    }

    public function test_a_source_entry_locks_servings_and_cannot_be_batched_twice(): void
    {
        $user = User::factory()->create();
        $plan = MealPlan::factory()->create(['created_by' => $user->id, 'week_start' => MealPlan::weekStartFor(now())->toDateString()]);
        $entry = MealPlanEntry::factory()->create(['meal_plan_id' => $plan->id, 'recipe_id' => $this->recipe()->id, 'servings' => 4, 'meal_type' => 'dinner', 'planned_on' => now()->toDateString()]);
        $batch = $this->service()->createFromEntry($user, $entry);

        $this->assertSame(4, $batch->prepared_portions);

        try {
            $this->service()->createFromEntry($user, $entry);
            $this->fail('Batched twice.');
        } catch (ValidationException) {
            $this->assertSame(1, BatchCook::count());
        }

        $this->expectException(ValidationException::class);
        app(MealPlanService::class)->updateEntry($user, $entry, $entry->version, ['servings' => 8]);
    }

    public function test_leftover_servings_and_eaten_leftovers_cannot_be_edited(): void
    {
        $user = User::factory()->create();
        $batch = $this->batch($user);
        $entry = $this->leftover($user, $batch, 2);
        $plans = app(MealPlanService::class);

        try {
            $plans->updateEntry($user, $entry, $entry->version, ['servings' => 3]);
            $this->fail('Servings changed.');
        } catch (ValidationException) {
            $this->assertSame(2, $entry->fresh()->servings);
        }

        $this->service()->consumeLeftover($user, $entry);
        $this->expectException(ValidationException::class);
        $plans->updateEntry($user, $entry->fresh(), $entry->fresh()->version, ['notes' => 'x']);
    }

    public function test_removing_an_unconsumed_leftover_releases_the_reservation(): void
    {
        $user = User::factory()->create();
        $batch = $this->batch($user);
        $entry = $this->leftover($user, $batch, 4);

        app(MealPlanService::class)->removeEntry($user, $entry, $entry->version);

        $batch->refresh();
        $this->assertSame(0, $batch->reserved_portions);
        $this->assertSame(6, $batch->availablePortions());
        $this->assertSame(0, MealPlanEntry::whereNotNull('batch_cook_id')->count());
        $this->assertLedgerMatches($batch);
    }

    public function test_removing_an_eaten_leftover_keeps_the_consumed_total(): void
    {
        $user = User::factory()->create();
        $batch = $this->batch($user);
        $entry = $this->leftover($user, $batch, 2);
        $this->service()->consumeLeftover($user, $entry);

        $fresh = $entry->fresh();
        $this->service()->removeLeftover($user, $fresh, $fresh->version);

        $batch->refresh();
        $this->assertSame([0, 2], [$batch->reserved_portions, $batch->consumed_portions]);
        $this->assertLedgerMatches($batch);
    }

    public function test_stale_versions_are_rejected_for_remove_and_cancel(): void
    {
        $user = User::factory()->create();
        $batch = $this->batch($user);
        $entry = $this->leftover($user, $batch, 2);

        try {
            $this->service()->removeLeftover($user, $entry, $entry->version + 5);
            $this->fail('Stale remove accepted.');
        } catch (StaleMealPlanException) {
            $this->assertSame(2, $batch->fresh()->reserved_portions);
        }

        $this->expectException(StaleBatchCookException::class);
        $this->service()->cancel($user, $batch, 1);
    }

    public function test_cancelling_removes_leftovers_but_not_after_consumption(): void
    {
        $user = User::factory()->create();
        $batch = $this->batch($user);
        $this->leftover($user, $batch, 2);
        $this->service()->cancel($user, $batch->fresh(), $batch->fresh()->version);

        $this->assertSame(0, BatchCook::count());
        $this->assertSame(0, MealPlanEntry::count());

        $eaten = $this->batch($user);
        $this->service()->consume($user, $eaten, 1, 'k');

        $this->expectException(ValidationException::class);
        $this->service()->cancel($user, $eaten->fresh(), $eaten->fresh()->version);
    }

    public function test_roles_control_who_can_plan_and_consume(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $viewer = User::factory()->create();
        $outsider = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $household = $this->household($owner, [[$member, 'member'], [$viewer, 'viewer']]);
        $batch = $this->batch($owner, $household);

        $this->service()->consume($member, $batch, 1, 'm');

        foreach ([$viewer, $outsider, $admin] as $denied) {
            try {
                $this->service()->consume($denied, $batch, 1, 'x'.$denied->id);
                $this->fail('Consumption allowed.');
            } catch (AuthorizationException) {
                $this->assertSame(1, $batch->fresh()->consumed_portions);
            }
        }

        $this->assertCount(1, $this->service()->list($viewer, $household));
        $this->expectException(HttpException::class);
        $this->service()->list($outsider, $household);
    }

    public function test_personal_batches_are_private_to_their_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $batch = $this->batch($owner);

        $this->assertCount(0, $this->service()->list($other, null));

        $this->expectException(AuthorizationException::class);
        $this->service()->consume($other, $batch, 1, 'k');
    }

    public function test_shared_batches_require_a_recipe_every_member_can_view(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $household = $this->household($owner, [[$member, 'member']]);

        $this->expectException(ValidationException::class);
        $this->service()->create($owner, $household, $this->recipe('Secret', false, $owner), 4, now()->toDateString());
    }

    public function test_unavailable_recipes_expose_nothing(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $household = $this->household($owner, [[$member, 'member']]);
        $recipe = $this->recipe('Public Soup');
        $this->batch($owner, $household, $recipe);
        $recipe->forceFill(['visibility' => 'private', 'author_id' => $owner->id])->save();

        $row = $this->service()->batchesView($member, $household, 'en')->first();

        $this->assertFalse($row['available']);
        $this->assertNull($row['title']);
        $this->assertTrue($this->service()->batchesView($owner, $household, 'en')->first()['available']);
    }

    public function test_leftover_plan_scope_matches_the_batch_scope(): void
    {
        $owner = User::factory()->create();
        $household = $this->household($owner);
        $batch = $this->batch($owner, $household);
        $entry = $this->leftover($owner, $batch, 2);

        $this->assertSame($household->id, $entry->mealPlan->household_id);
        $this->assertSame($batch->id, $entry->batch_cook_id);
    }

    public function test_batch_limit_is_enforced(): void
    {
        config(['planner.max_batches_per_scope' => 1]);
        $user = User::factory()->create();
        $this->batch($user);

        $this->expectException(ValidationException::class);
        $this->batch($user);
    }

    public function test_page_renders_in_both_languages_and_guards_routes(): void
    {
        $user = User::factory()->create();
        $this->batch($user);
        $this->get(route('planner.batches', ['locale' => 'en']))->assertRedirect('/login');

        Livewire::actingAs($user)->test(BatchCooking::class, ['locale' => 'en'])->assertSee('Batch cooking')->assertSee('6 prepared');
        Livewire::actingAs($user)->test(BatchCooking::class, ['locale' => 'el'])->assertSee('Μαγείρεμα σε παρτίδες');

        $household = $this->household(User::factory()->create());
        $this->actingAs($user)->get(route('households.batches', ['locale' => 'en', 'household' => $household->id]))->assertNotFound();
        $this->actingAs($user)->get(route('planner.batches', ['locale' => 'el']))->assertOk();
    }

    public function test_page_actions_schedule_and_consume_with_idempotent_key(): void
    {
        $user = User::factory()->create();
        $batch = $this->batch($user);

        $component = Livewire::actingAs($user)->test(BatchCooking::class, ['locale' => 'en'])
            ->call('open', $batch->id)
            ->set('eatPortions', 2)
            ->call('consume')
            ->call('consume');

        $this->assertSame(4, $batch->fresh()->consumed_portions);
        $component->set('eatPortions', 99)->call('consume')->assertHasErrors('portions');
    }

    public function test_english_and_greek_batch_strings_have_the_same_keys(): void
    {
        $en = Arr::dot(trans('planner.batches', [], 'en'));
        $el = Arr::dot(trans('planner.batches', [], 'el'));

        $this->assertSame([], array_diff_key($en, $el));
        $this->assertSame([], array_diff_key($el, $en));
    }
}
