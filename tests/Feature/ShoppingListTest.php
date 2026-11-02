<?php

namespace Tests\Feature;

use App\Enums\MealType;
use App\Exceptions\StaleShoppingListException;
use App\Livewire\ShoppingListPage;
use App\Models\Household;
use App\Models\Ingredient;
use App\Models\IngredientSubstitution;
use App\Models\MealPlan;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\Unit;
use App\Models\User;
use App\Services\MealPlanService;
use App\Services\ShoppingListService;
use Database\Seeders\AdditionalUnitSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ShoppingListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([UnitSeeder::class, AdditionalUnitSeeder::class]);
    }

    private function service(): ShoppingListService
    {
        return app(ShoppingListService::class);
    }

    private function unit(string $code): Unit
    {
        return Unit::where('code', $code)->firstOrFail();
    }

    private function ingredient(string $en = 'Flour', string $el = 'Αλεύρι'): Ingredient
    {
        $ingredient = Ingredient::factory()->create();
        $ingredient->fill(['en' => ['name' => $en], 'el' => ['name' => $el]])->save();

        return $ingredient;
    }

    /** A published two-serving recipe with one ingredient line. */
    private function recipe(Ingredient $ingredient, string $unit, ?string $quantity, array $line = [], bool $public = true, ?User $author = null): Recipe
    {
        $recipe = $public ? Recipe::factory()->published()->create(['base_servings' => 2]) : Recipe::factory()->create(['base_servings' => 2, 'author_id' => $author?->id, 'visibility' => 'private']);
        RecipeIngredient::factory()->create([...$line, 'recipe_id' => $recipe->id, 'ingredient_id' => $ingredient->id, 'unit_id' => $this->unit($unit)->id, 'quantity' => $quantity]);

        return $recipe;
    }

    private function plan(User $owner, ?Household $household = null): MealPlan
    {
        return MealPlan::factory()->create(['created_by' => $owner->id, 'household_id' => $household?->id, 'week_start' => MealPlan::weekStartFor(now())->toDateString()]);
    }

    private function plan_entry(MealPlan $plan, Recipe $recipe, int $servings = 2, string $meal = 'dinner'): MealPlanEntry
    {
        return MealPlanEntry::factory()->create(['meal_plan_id' => $plan->id, 'recipe_id' => $recipe->id, 'servings' => $servings, 'meal_type' => $meal, 'planned_on' => $plan->week_start->toDateString()]);
    }

    private function household(User $owner, array $members = []): Household
    {
        $household = Household::factory()->ownedBy($owner)->create();

        foreach ($members as [$user, $role]) {
            $household->members()->create(['user_id' => $user->id, 'role' => $role]);
        }

        return $household;
    }

    private function generated(User $user, MealPlan $plan): ShoppingList
    {
        $list = $this->service()->ensureList($user, $plan);
        $this->service()->generate($user, $list);

        return $list->fresh();
    }

    private function itemFor(ShoppingList $list, Ingredient $ingredient, bool $optional = false): ?ShoppingListItem
    {
        return $list->items()->where('ingredient_id', $ingredient->id)->where('is_optional', $optional)->first();
    }

    public function test_generation_consolidates_recipes_and_converts_units(): void
    {
        $user = User::factory()->create();
        $flour = $this->ingredient();
        $plan = $this->plan($user);
        $this->plan_entry($plan, $this->recipe($flour, 'g', '200'), 4);
        $this->plan_entry($plan, $this->recipe($flour, 'kg', '0.5'), 2, 'lunch');

        $item = $this->itemFor($this->generated($user, $plan), $flour);

        $this->assertSame('900.0000', $item->quantity);
        $this->assertSame('g', $item->unit->code);
        $this->assertSame(1, $this->generatedCount($plan));
    }

    public function test_confirmed_substitutions_keep_no_inferred_quantity_and_other_lines_scale_to_servings(): void
    {
        $user = User::factory()->create();
        $original = $this->ingredient('Yogurt', 'Γιαούρτι');
        $replacement = $this->ingredient('Sour cream', 'Ξινή κρέμα');
        $sugar = $this->ingredient('Sugar', 'Ζάχαρη');
        $plan = $this->plan($user);
        $recipe = $this->recipe($original, 'g', '200');
        RecipeIngredient::factory()->create([
            'recipe_id' => $recipe->getKey(),
            'ingredient_id' => $sugar->getKey(),
            'unit_id' => $this->unit('g')->getKey(),
            'quantity' => '100',
        ]);
        $substitution = IngredientSubstitution::factory()->approved()->create([
            'ingredient_id' => $original->getKey(),
            'replacement_ingredient_id' => $replacement->getKey(),
        ]);
        $entry = app(MealPlanService::class)->addEntry(
            $user,
            $plan,
            $recipe,
            $plan->week_start,
            MealType::Dinner,
            4,
            confirmedSubstitutions: [$original->getKey() => [
                'substitution_id' => $substitution->getKey(),
                'replacement_ingredient_id' => $replacement->getKey(),
            ]],
        );
        $list = $this->generated($user, $plan);

        $this->assertDatabaseHas('meal_plan_entry_substitutions', [
            'meal_plan_entry_id' => $entry->getKey(),
            'recipe_ingredient_id' => $recipe->recipeIngredients()->where('ingredient_id', $original->getKey())->value('id'),
            'ingredient_substitution_id' => $substitution->getKey(),
            'replacement_ingredient_id' => $replacement->getKey(),
            'confirmed_by' => $user->getKey(),
        ]);
        $this->assertDatabaseHas('shopping_list_items', [
            'shopping_list_id' => $list->getKey(),
            'ingredient_id' => $replacement->getKey(),
            'quantity' => null,
            'unit_id' => null,
            'is_manual' => false,
        ]);
        $this->assertDatabaseHas('shopping_list_items', [
            'shopping_list_id' => $list->getKey(),
            'ingredient_id' => $sugar->getKey(),
            'quantity' => '200.0000',
        ]);
        $this->assertDatabaseMissing('shopping_list_items', [
            'shopping_list_id' => $list->getKey(),
            'ingredient_id' => $original->getKey(),
        ]);

        $changedReplacement = $this->ingredient('Crème fraîche', 'Κρέμα φρέσκα');
        $substitution->update(['replacement_ingredient_id' => $changedReplacement->getKey()]);
        $this->service()->generate($user, $list);

        $this->assertDatabaseHas('shopping_list_items', [
            'shopping_list_id' => $list->getKey(),
            'ingredient_id' => $original->getKey(),
            'quantity' => '400.0000',
        ]);
        $this->assertDatabaseMissing('shopping_list_items', [
            'shopping_list_id' => $list->getKey(),
            'ingredient_id' => $replacement->getKey(),
        ]);
        $this->assertDatabaseMissing('shopping_list_items', [
            'shopping_list_id' => $list->getKey(),
            'ingredient_id' => $changedReplacement->getKey(),
        ]);

        $substitution->update(['status' => 'inactive']);
        $this->service()->generate($user, $list);

        $this->assertDatabaseHas('shopping_list_items', [
            'shopping_list_id' => $list->getKey(),
            'ingredient_id' => $original->getKey(),
            'quantity' => '400.0000',
        ]);
    }

    private function generatedCount(MealPlan $plan): int
    {
        return ShoppingListItem::query()->whereIn('shopping_list_id', ShoppingList::where('meal_plan_id', $plan->id)->select('id'))->count();
    }

    public function test_ranges_buy_the_upper_bound_and_incompatible_units_stay_separate(): void
    {
        $user = User::factory()->create();
        $garlic = $this->ingredient('Garlic', 'Σκόρδο');
        $plan = $this->plan($user);
        $this->plan_entry($plan, $this->recipe($garlic, 'clove', '2', ['quantity_max' => '4']), 2);
        $this->plan_entry($plan, $this->recipe($garlic, 'g', '10'), 2, 'lunch');
        $this->plan_entry($plan, $this->recipe($garlic, 'pinch', '1'), 2, 'breakfast');

        $list = $this->generated($user, $plan);

        $this->assertSame(3, $list->items()->count());
        $this->assertSame('4.0000', $list->items()->where('unit_id', $this->unit('clove')->id)->first()->quantity);
    }

    public function test_generation_is_idempotent(): void
    {
        $user = User::factory()->create();
        $flour = $this->ingredient();
        $plan = $this->plan($user);
        $this->plan_entry($plan, $this->recipe($flour, 'g', '200'));
        $this->plan_entry($plan, $this->recipe($flour, 'g', '100', ['is_optional' => true]), 2, 'lunch');

        $list = $this->generated($user, $plan);
        $before = $list->items()->orderBy('id')->get(['id', 'quantity', 'version', 'updated_at'])->toArray();

        $this->service()->generate($user, $list);
        $this->service()->generate($user, $list);

        $this->assertSame($before, $list->items()->orderBy('id')->get(['id', 'quantity', 'version', 'updated_at'])->toArray());
        $this->assertSame(2, $list->items()->count());
        $this->assertTrue($this->itemFor($list, $flour, true)->is_optional);
    }

    public function test_manual_items_and_checked_state_survive_regeneration(): void
    {
        $user = User::factory()->create();
        $flour = $this->ingredient();
        $plan = $this->plan($user);
        $this->plan_entry($plan, $this->recipe($flour, 'g', '200'));
        $list = $this->generated($user, $plan);

        $manual = $this->service()->addManualItem($user, $list, 'Dish soap', '2');
        $item = $this->itemFor($list, $flour);
        $this->service()->setChecked($user, $item, $item->version, true);

        $this->service()->generate($user, $list);

        $this->assertNotNull($this->itemFor($list, $flour)->checked_at);
        $this->assertNull($this->itemFor($list, $flour)->extra_quantity);
        $this->assertSame('Dish soap', $manual->fresh()->custom_name);
        $this->assertSame($manual->version, $manual->fresh()->version);
    }

    public function test_checked_item_keeps_checked_and_shows_the_extra_amount_needed(): void
    {
        $user = User::factory()->create();
        $flour = $this->ingredient();
        $plan = $this->plan($user);
        $this->plan_entry($plan, $this->recipe($flour, 'g', '800'), 2);
        $list = $this->generated($user, $plan);

        $item = $this->itemFor($list, $flour);
        $this->service()->setChecked($user, $item, $item->version, true);

        // Another 0.4 kg is now planned, taking the total across the g/kg boundary.
        $this->plan_entry($plan, $this->recipe($flour, 'kg', '0.4'), 2, 'lunch');
        $this->service()->generate($user, $list);

        $item = $this->itemFor($list, $flour);
        $this->assertNotNull($item->checked_at);
        $this->assertSame('1.2000', $item->quantity);
        $this->assertSame('kg', $item->unit->code);
        $this->assertSame('0.4000', $item->extra_quantity);

        $view = $this->service()->itemsView($user, $list, 'en')->first();
        $this->assertTrue($view['checked']);
        $this->assertStringContainsString('0.4', $view['extra']);

        // Ticking it again acknowledges the new total; the extra is cleared, never silently assumed bought.
        $this->service()->setChecked($user, $item, $item->version, false);
        $item = $this->itemFor($list, $flour);
        $this->service()->setChecked($user, $item, $item->version, true);
        $this->assertNull($this->itemFor($list, $flour)->extra_quantity);
    }

    public function test_a_smaller_requirement_does_not_create_an_extra(): void
    {
        $user = User::factory()->create();
        $flour = $this->ingredient();
        $plan = $this->plan($user);
        $entry = $this->plan_entry($plan, $this->recipe($flour, 'g', '800'), 2);
        $list = $this->generated($user, $plan);
        $item = $this->itemFor($list, $flour);
        $this->service()->setChecked($user, $item, $item->version, true);

        $entry->update(['servings' => 1]);
        $this->service()->generate($user, $list);

        $this->assertNull($this->itemFor($list, $flour)->extra_quantity);
        $this->assertSame('400.0000', $this->itemFor($list, $flour)->quantity);
    }

    public function test_items_no_longer_needed_are_dropped_unless_already_bought(): void
    {
        $user = User::factory()->create();
        $flour = $this->ingredient();
        $salt = $this->ingredient('Salt', 'Αλάτι');
        $plan = $this->plan($user);
        $entry = $this->plan_entry($plan, $this->recipe($flour, 'g', '200'));
        $this->plan_entry($plan, $this->recipe($salt, 'g', '5'), 2, 'lunch')->recipe_id;
        $list = $this->generated($user, $plan);
        $bought = $this->itemFor($list, $flour);
        $this->service()->setChecked($user, $bought, $bought->version, true);

        $plan->entries()->delete();
        $this->service()->generate($user, $list);

        $this->assertNull($this->itemFor($list, $salt));
        $kept = $this->itemFor($list, $flour);
        $this->assertTrue($kept->is_stale);
        $this->assertNotNull($kept->checked_at);

        $this->service()->setChecked($user, $kept, $kept->version, false);
        $this->assertNull($this->itemFor($list, $flour));
        $this->assertNotNull($entry);
    }

    public function test_private_recipes_not_visible_to_every_member_are_excluded_without_leaking(): void
    {
        $owner = User::factory()->create();
        $friend = User::factory()->create();
        $household = $this->household($owner, [[$friend, 'member']]);
        $secret = $this->ingredient('Truffle', 'Τρούφα');
        $flour = $this->ingredient();
        $plan = $this->plan($owner, $household);
        $this->plan_entry($plan, $this->recipe($secret, 'g', '50', [], false, $owner), 2);
        $this->plan_entry($plan, $this->recipe($flour, 'g', '100'), 2, 'lunch');

        $list = $this->service()->ensureList($owner, $plan);
        $result = $this->service()->generate($owner, $list);

        $this->assertSame(1, $result['excluded_entries']);
        $this->assertNull($this->itemFor($list, $secret));
        $names = $this->service()->itemsView($friend, $list, 'en')->pluck('name')->all();
        $this->assertSame(['Flour'], $names);
    }

    public function test_authorization_follows_household_roles_and_never_global_roles(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $viewer = User::factory()->create();
        $outsider = User::factory()->create();
        $household = $this->household($owner, [[$member, 'member'], [$viewer, 'viewer']]);
        $plan = $this->plan($owner, $household);
        $list = $this->service()->ensureList($member, $plan);

        $this->assertTrue($viewer->can('view', $list));
        $this->assertFalse($viewer->can('update', $list));
        $this->assertTrue($member->can('update', $list));
        $this->assertFalse($outsider->can('view', $list));
        $this->assertFalse(User::factory()->admin()->create()->can('view', $list));

        $this->expectException(AuthorizationException::class);
        $this->service()->generate($viewer, $list);
    }

    public function test_viewers_cannot_create_lists_or_add_items_and_outsiders_cannot_read(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $household = $this->household($owner, [[$viewer, 'viewer']]);
        $plan = $this->plan($owner, $household);

        try {
            $this->service()->ensureList($viewer, $plan);
            $this->fail('A viewer created a list.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->expectException(AuthorizationException::class);
        $this->service()->findList(User::factory()->create(), $plan);
    }

    public function test_personal_list_belongs_to_its_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $list = $this->service()->ensureList($owner, $this->plan($owner));

        $this->assertTrue($owner->can('update', $list));
        $this->assertFalse($other->can('view', $list));
    }

    public function test_stale_item_versions_are_rejected_and_nothing_is_double_applied(): void
    {
        $user = User::factory()->create();
        $flour = $this->ingredient();
        $plan = $this->plan($user);
        $this->plan_entry($plan, $this->recipe($flour, 'g', '200'));
        $list = $this->generated($user, $plan);
        $item = $this->itemFor($list, $flour);

        $this->service()->setChecked($user, $item, $item->version, true);
        $checkedAt = $this->itemFor($list, $flour)->checked_at;

        try {
            // A second device still holding the old version tries the same change.
            $this->service()->setChecked($user, $item, $item->version, true);
            $this->fail('Stale toggle was accepted.');
        } catch (StaleShoppingListException) {
        }

        $this->assertSame($item->version + 1, $this->itemFor($list, $flour)->version);
        $this->assertEquals($checkedAt, $this->itemFor($list, $flour)->checked_at);
    }

    public function test_a_tick_made_against_an_item_changed_by_regeneration_is_a_conflict(): void
    {
        $user = User::factory()->create();
        $flour = $this->ingredient();
        $plan = $this->plan($user);
        $entry = $this->plan_entry($plan, $this->recipe($flour, 'g', '200'));
        $list = $this->generated($user, $plan);
        $stale = $this->itemFor($list, $flour);

        $entry->update(['servings' => 4]);
        $this->service()->generate($user, $list);

        $this->expectException(StaleShoppingListException::class);
        $this->service()->setChecked($user, $stale, $stale->version, true);
    }

    public function test_only_manual_items_can_be_removed_and_removal_is_version_checked(): void
    {
        $user = User::factory()->create();
        $flour = $this->ingredient();
        $plan = $this->plan($user);
        $this->plan_entry($plan, $this->recipe($flour, 'g', '200'));
        $list = $this->generated($user, $plan);
        $manual = $this->service()->addManualItem($user, $list, 'Napkins');

        try {
            $this->service()->removeManualItem($user, $manual, 99);
            $this->fail('Stale removal accepted.');
        } catch (StaleShoppingListException) {
        }

        $this->service()->removeManualItem($user, $manual, $manual->version);
        $this->service()->removeManualItem($user, $manual, $manual->version);
        $this->assertSame(0, $list->items()->where('is_manual', true)->count());

        $this->expectException(HttpException::class);
        $this->service()->removeManualItem($user, $this->itemFor($list, $flour), 1);
    }

    public function test_manual_item_validation_and_limit(): void
    {
        $user = User::factory()->create();
        $list = $this->service()->ensureList($user, $this->plan($user));

        foreach ([['', null], ['Milk', 'abc'], ['Milk', '0'], ['Milk', '-1']] as [$name, $quantity]) {
            try {
                $this->service()->addManualItem($user, $list, $name, $quantity);
                $this->fail('Invalid item accepted.');
            } catch (ValidationException) {
            }
        }

        config(['planner.max_shopping_items_per_list' => 1]);
        $this->service()->addManualItem($user, $list, 'Milk', '1.5', $this->unit('l')->id);
        $this->expectException(ValidationException::class);
        $this->service()->addManualItem($user, $list, 'Eggs');
    }

    public function test_database_rejects_inconsistent_items_and_duplicate_keys(): void
    {
        $list = ShoppingList::factory()->create();
        $ingredient = $this->ingredient();

        $insert = fn (array $values) => DB::transaction(fn () => ShoppingListItem::query()->create(['shopping_list_id' => $list->id, ...$values]));

        try {
            $insert(['is_manual' => true]);
            $this->fail('Item without subject stored.');
        } catch (QueryException) {
        }

        $insert(['ingredient_id' => $ingredient->id, 'generated_key' => 'k', 'is_manual' => false]);
        $this->expectException(QueryException::class);
        $insert(['ingredient_id' => $ingredient->id, 'generated_key' => 'k', 'is_manual' => false]);
    }

    public function test_page_generates_toggles_and_adds_items_in_both_languages(): void
    {
        $user = User::factory()->create();
        $flour = $this->ingredient();
        $plan = $this->plan($user);
        $this->plan_entry($plan, $this->recipe($flour, 'g', '200'));

        $component = Livewire::actingAs($user)->test(ShoppingListPage::class, ['locale' => 'en'])
            ->call('generate')
            ->assertSee('Flour')
            ->set('manualName', 'Dish soap')
            ->call('addManual')
            ->assertSee('Dish soap');

        $item = $this->itemFor(ShoppingList::first(), $flour);
        $component->call('setChecked', $item->id, $item->version, true);
        $this->assertNotNull($item->fresh()->checked_at);

        Livewire::actingAs($user)->test(ShoppingListPage::class, ['locale' => 'el'])->assertSee('Αλεύρι')->assertSee('Λίστα αγορών');
    }

    public function test_page_cannot_reach_items_of_other_lists_and_reports_conflicts(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $foreign = $this->service()->addManualItem($other, $this->service()->ensureList($other, $this->plan($other)), 'Private thing');
        $this->service()->ensureList($user, $this->plan($user));

        Livewire::actingAs($user)->test(ShoppingListPage::class, ['locale' => 'en'])
            ->call('setChecked', $foreign->id, $foreign->version, true)
            ->assertHasErrors('conflict')
            ->call('removeManual', $foreign->id, $foreign->version);

        $this->assertNull($foreign->fresh()->checked_at);
        $this->assertNotNull($foreign->fresh());
    }

    public function test_viewers_see_the_list_without_edit_controls(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $household = $this->household($owner, [[$viewer, 'viewer']]);
        $this->service()->addManualItem($owner, $this->service()->ensureList($owner, $this->plan($owner, $household)), 'Bread');

        Livewire::actingAs($viewer)->test(ShoppingListPage::class, ['locale' => 'en', 'householdId' => $household->id])
            ->assertSee('Bread')
            ->assertDontSee('Add item')
            ->assertDontSee('Update list from plan');
    }

    public function test_routes_hide_households_from_outsiders_and_guests(): void
    {
        $owner = User::factory()->create();
        $household = $this->household($owner);

        $this->get(route('planner.shopping', ['locale' => 'en']))->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get(route('households.shopping', ['locale' => 'en', 'household' => $household->id]))->assertNotFound();
        $this->actingAs($owner)->get(route('households.shopping', ['locale' => 'el', 'household' => $household->id]))->assertOk()->assertSee('Λίστα αγορών');
    }

    public function test_every_english_planner_key_has_a_greek_translation(): void
    {
        $this->assertSame(array_keys(Arr::dot(require lang_path('en/planner.php'))), array_keys(Arr::dot(require lang_path('el/planner.php'))));
    }
}
