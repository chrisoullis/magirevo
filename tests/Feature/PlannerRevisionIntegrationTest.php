<?php

namespace Tests\Feature;

use App\Enums\MealType;
use App\Exceptions\StaleMealPlanException;
use App\Livewire\MealPlanner;
use App\Models\MealPlan;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\ShoppingList;
use App\Models\User;
use App\Services\BatchCookService;
use App\Services\MealPlanService;
use App\Services\RecipePublishingService;
use App\Services\RecipeRevisionService;
use App\Services\ShoppingListService;
use Database\Seeders\AdditionalUnitSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class PlannerRevisionIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    private User $cook;

    private Recipe $recipe;

    private MealPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([UnitSeeder::class, AdditionalUnitSeeder::class]);
        $this->author = User::factory()->contributor()->create();
        $this->cook = User::factory()->create();
        $this->recipe = Recipe::factory()->complete()->create(['author_id' => $this->author->id]);
        $this->actingAs(User::factory()->admin()->create());
        app(RecipePublishingService::class)->publish($this->recipe);
        $this->recipe->refresh();
        $this->plan = MealPlan::factory()->create(['created_by' => $this->cook->id, 'household_id' => null, 'week_start' => MealPlan::weekStartFor(now())->toDateString()]);
    }

    private function planEntry(): MealPlanEntry
    {
        return app(MealPlanService::class)->addEntry($this->cook, $this->plan, $this->recipe, $this->plan->week_start, MealType::Dinner, 2);
    }

    private function publishWithQuantity(string $quantity): void
    {
        $service = app(RecipeRevisionService::class);
        $draft = $service->createDraft($this->author, $this->recipe->fresh());
        $payload = $draft->payload;
        $payload['ingredients'][0]['quantity'] = $quantity;
        $service->approve(User::factory()->admin()->create(), $service->submit($this->author, $service->saveDraft($this->author, $draft, $payload)));
        $this->recipe->refresh();
    }

    /**
     * @return array<int, string|null>
     */
    private function listQuantities(ShoppingList $list): array
    {
        return $list->items()->orderBy('id')->pluck('quantity', 'id')->all();
    }

    public function test_a_new_entry_records_the_revision_it_was_planned_against(): void
    {
        $entry = $this->planEntry();

        $this->assertSame($this->recipe->current_revision_id, $entry->planned_recipe_revision_id);
    }

    public function test_a_published_change_flags_the_entry_without_touching_the_shopping_list(): void
    {
        $entry = $this->planEntry();
        $shopping = app(ShoppingListService::class);
        $list = $shopping->ensureList($this->cook, $this->plan);
        $shopping->generate($this->cook, $list);
        $manual = $shopping->addManualItem($this->cook, $list->fresh(), 'Dish soap', '2');
        $before = $this->listQuantities($list->fresh());

        $this->publishWithQuantity('99');

        $item = app(MealPlanService::class)->weekView($this->cook, $this->plan, 'en')->flatten(2)->first();
        $this->assertTrue($item['recipe_updated']);
        $this->assertSame($before, $this->listQuantities($list->fresh()));
        $this->assertSame($entry->planned_recipe_revision_id, $entry->fresh()->planned_recipe_revision_id);

        $shopping->generate($this->cook, $list->fresh());

        $this->assertNotSame($before, $this->listQuantities($list->fresh()));
        $this->assertSame('Dish soap', $manual->fresh()->custom_name);
    }

    public function test_acknowledging_repins_the_entry_and_leaves_the_shopping_list_alone(): void
    {
        $entry = $this->planEntry();
        $shopping = app(ShoppingListService::class);
        $list = $shopping->ensureList($this->cook, $this->plan);
        $shopping->generate($this->cook, $list);
        $before = $this->listQuantities($list->fresh());
        $this->publishWithQuantity('99');

        app(MealPlanService::class)->acknowledgeRecipeUpdate($this->cook, $entry->fresh(), $entry->version);

        $this->assertSame($this->recipe->current_revision_id, $entry->fresh()->planned_recipe_revision_id);
        $this->assertFalse(app(MealPlanService::class)->weekView($this->cook, $this->plan, 'en')->flatten(2)->first()['recipe_updated']);
        $this->assertSame($before, $this->listQuantities($list->fresh()));
    }

    public function test_acknowledging_requires_the_current_version_and_plan_access(): void
    {
        $entry = $this->planEntry();
        $this->publishWithQuantity('99');

        try {
            app(MealPlanService::class)->acknowledgeRecipeUpdate($this->cook, $entry->fresh(), $entry->version + 5);
            $this->fail('A stale version must conflict.');
        } catch (StaleMealPlanException) {
            $this->assertNotSame($this->recipe->current_revision_id, $entry->fresh()->planned_recipe_revision_id);
        }

        $this->expectException(AuthorizationException::class);
        app(MealPlanService::class)->acknowledgeRecipeUpdate(User::factory()->create(), $entry->fresh(), $entry->version);
    }

    public function test_unpublishing_hides_the_planned_recipe_even_when_flagged(): void
    {
        $this->planEntry();
        $this->publishWithQuantity('99');
        DB::table('recipes')->where('id', $this->recipe->id)->update(['visibility' => 'private']);

        $item = app(MealPlanService::class)->weekView($this->cook, $this->plan, 'en')->flatten(2)->first();

        $this->assertFalse($item['available']);
        $this->assertFalse($item['recipe_updated']);
        $this->assertNull($item['title']);
    }

    public function test_cooking_records_keep_the_revision_they_were_made_with(): void
    {
        $entry = $this->planEntry();
        $batch = app(BatchCookService::class)->createFromEntry($this->cook, $entry);
        $original = $this->recipe->current_revision_id;

        $this->publishWithQuantity('99');

        $this->assertSame($original, $batch->fresh()->recipe_revision_id);
        $this->assertNotSame($original, $this->recipe->current_revision_id);
    }

    public function test_the_notice_is_shown_in_english_and_greek(): void
    {
        $this->planEntry();
        $this->publishWithQuantity('99');
        $this->actingAs($this->cook);

        foreach (['en' => 'Recipe updated since you planned this meal', 'el' => 'Η συνταγή ενημερώθηκε'] as $locale => $text) {
            app()->setLocale($locale);
            Livewire::test(MealPlanner::class, ['locale' => $locale])->assertSee($text);
        }
    }

    public function test_planned_line_ids_of_unchanged_ingredients_are_preserved_across_revisions(): void
    {
        $this->planEntry();
        $line = RecipeIngredient::where('recipe_id', $this->recipe->id)->orderBy('id')->firstOrFail();

        $this->publishWithQuantity('99');

        $this->assertDatabaseHas('recipe_ingredients', ['id' => $line->id, 'recipe_id' => $this->recipe->id]);
    }
}
