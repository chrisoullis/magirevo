<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Ingredient;
use App\Models\MealPlan;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\Unit;
use App\Models\User;
use App\Services\IngredientConsolidator;
use App\Services\PlanIngredientCollector;
use App\Services\ServingAdjustmentService;
use App\Services\UnitConverter;
use App\Support\QuantityLine;
use Database\Seeders\AdditionalUnitSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QuantityEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([UnitSeeder::class, AdditionalUnitSeeder::class]);
    }

    private function unit(string $code): Unit
    {
        return Unit::where('code', $code)->firstOrFail();
    }

    private function line(Ingredient $ingredient, ?string $unit, ?string $quantity, ?string $max = null, bool $optional = false): QuantityLine
    {
        return new QuantityLine($ingredient->id, $unit ? $this->unit($unit) : null, $quantity, $max, $optional);
    }

    private function consolidate(array $lines): array
    {
        return app(IngredientConsolidator::class)->consolidate($lines);
    }

    public function test_new_units_are_seeded_in_both_languages_and_original_units_are_untouched(): void
    {
        $this->assertSame(10, Unit::count());
        $this->seed(AdditionalUnitSeeder::class);
        $this->assertSame(10, Unit::count());

        $cup = $this->unit('cup_metric');
        $this->assertSame('250.00000000', $cup->base_conversion);
        $this->assertStringContainsString('metric', $cup->translate('en')->name);
        $this->assertSame('σκελίδα', $this->unit('clove')->translate('el')->name);
        $this->assertSame('πρέζα', $this->unit('pinch')->translate('el')->name);
        $this->assertSame('count', $this->unit('pinch')->unit_type);
        $this->assertSame('15.00000000', $this->unit('tbsp')->base_conversion);
    }

    public function test_volume_units_consolidate_and_cup_is_250_ml(): void
    {
        $oil = Ingredient::factory()->create();

        $result = $this->consolidate([$this->line($oil, 'tsp', '2'), $this->line($oil, 'tbsp', '1')]);
        $this->assertCount(1, $result);
        $this->assertSame('25.0000', $result[0]->quantity);
        $this->assertSame('ml', $result[0]->unit->code);

        $result = $this->consolidate([$this->line($oil, 'cup_metric', '2'), $this->line($oil, 'ml', '500')]);
        $this->assertSame('1.0000', $result[0]->quantity);
        $this->assertSame('l', $result[0]->unit->code);
    }

    public function test_mass_units_consolidate_to_a_readable_unit(): void
    {
        $flour = Ingredient::factory()->create();

        $result = $this->consolidate([$this->line($flour, 'g', '700'), $this->line($flour, 'kg', '0.5')]);
        $this->assertSame('1.2000', $result[0]->quantity);
        $this->assertSame('kg', $result[0]->unit->code);

        $result = $this->consolidate([$this->line($flour, 'g', '200'), $this->line($flour, 'kg', '0.1')]);
        $this->assertSame('300.0000', $result[0]->quantity);
        $this->assertSame('g', $result[0]->unit->code);
    }

    public function test_same_unit_is_kept_and_summed(): void
    {
        $flour = Ingredient::factory()->create();
        $result = $this->consolidate([$this->line($flour, 'kg', '1.5'), $this->line($flour, 'kg', '0.25')]);

        $this->assertSame('1.7500', $result[0]->quantity);
        $this->assertSame('kg', $result[0]->unit->code);
        $this->assertFalse($result[0]->converted);
    }

    public function test_mass_volume_and_count_are_never_combined(): void
    {
        $garlic = Ingredient::factory()->create();

        $result = $this->consolidate([
            $this->line($garlic, 'g', '10'),
            $this->line($garlic, 'ml', '10'),
            $this->line($garlic, 'clove', '2'),
            $this->line($garlic, 'pinch', '1'),
            $this->line($garlic, 'piece', '1'),
        ]);

        $this->assertCount(5, $result);
        $this->assertEqualsCanonicalizing(['g', 'ml', 'clove', 'pinch', 'piece'], array_map(fn ($r) => $r->unit->code, $result));

        $converter = app(UnitConverter::class);
        $this->assertNull($converter->convert('1', $this->unit('g'), $this->unit('ml')));
        $this->assertNull($converter->convert('1', $this->unit('clove'), $this->unit('piece')));
        $this->assertSame('1000.0000', $converter->convert('1', $this->unit('l'), $this->unit('ml')));
    }

    public function test_unitless_and_unquantified_lines_are_separate_buckets(): void
    {
        $salt = Ingredient::factory()->create();

        $result = $this->consolidate([
            $this->line($salt, null, '2'), $this->line($salt, null, '3'),
            $this->line($salt, null, null), $this->line($salt, 'g', null),
        ]);

        $this->assertCount(2, $result);
        $byQuantity = collect($result)->keyBy(fn ($r) => $r->quantity ?? 'none');
        $this->assertSame('5.0000', $byQuantity['5.0000']->quantity);
        $this->assertNull($byQuantity['5.0000']->unit);
        $this->assertNull($byQuantity['none']->quantity);
    }

    public function test_optional_lines_never_inflate_required_totals(): void
    {
        $herb = Ingredient::factory()->create();

        $result = $this->consolidate([$this->line($herb, 'g', '10'), $this->line($herb, 'g', '5', null, true)]);

        $this->assertCount(2, $result);
        $required = collect($result)->firstWhere('isOptional', false);
        $optional = collect($result)->firstWhere('isOptional', true);
        $this->assertSame('10.0000', $required->quantity);
        $this->assertSame('5.0000', $optional->quantity);
    }

    public function test_ranges_are_summed_and_reported_only_when_they_differ(): void
    {
        $rice = Ingredient::factory()->create();

        $result = $this->consolidate([$this->line($rice, 'g', '100', '150'), $this->line($rice, 'g', '50')]);
        $this->assertSame('150.0000', $result[0]->quantity);
        $this->assertSame('200.0000', $result[0]->quantityMax);

        $result = $this->consolidate([$this->line($rice, 'g', '100'), $this->line($rice, 'g', '50')]);
        $this->assertNull($result[0]->quantityMax);
    }

    public function test_adjust_many_matches_adjust_and_handles_non_linear_lines(): void
    {
        $recipe = Recipe::factory()->create(['base_servings' => 4]);
        $linear = RecipeIngredient::factory()->create(['recipe_id' => $recipe->id, 'quantity' => '1.3333', 'quantity_max' => '2.5000']);
        $fixed = RecipeIngredient::factory()->create(['recipe_id' => $recipe->id, 'quantity' => '2.0000', 'scaling_mode' => 'fixed']);
        $service = app(ServingAdjustmentService::class);

        $this->assertSame([], $service->adjustMany([]));

        $many = $service->adjustMany([[$linear, '6'], [$linear, '1.5'], [$fixed->fresh(), '8']]);

        $this->assertSame($service->adjust($linear, '6'), $many[0]);
        $this->assertSame($service->adjust($linear, '1.5'), $many[1]);
        $this->assertSame($service->adjust($fixed->fresh(), '8'), $many[2]);
        $this->assertFalse($many[2]['was_scaled']);
    }

    public function test_adjust_many_rejects_invalid_servings(): void
    {
        $line = RecipeIngredient::factory()->create();

        $this->expectException(\InvalidArgumentException::class);
        app(ServingAdjustmentService::class)->adjustMany([[$line, '0']]);
    }

    private function recipeWithIngredient(Ingredient $ingredient, string $unit, string $quantity, bool $public = true): Recipe
    {
        $recipe = $public ? Recipe::factory()->published()->create(['base_servings' => 2]) : Recipe::factory()->create(['base_servings' => 2]);
        RecipeIngredient::factory()->create(['recipe_id' => $recipe->id, 'ingredient_id' => $ingredient->id, 'unit_id' => $this->unit($unit)->id, 'quantity' => $quantity]);

        return $recipe;
    }

    public function test_collector_scales_by_entry_servings_and_consolidates_across_recipes(): void
    {
        $user = User::factory()->create();
        $flour = Ingredient::factory()->create();
        $plan = MealPlan::factory()->create(['created_by' => $user->id]);
        $a = $this->recipeWithIngredient($flour, 'g', '200');
        $b = $this->recipeWithIngredient($flour, 'kg', '0.5');

        MealPlanEntry::factory()->create(['meal_plan_id' => $plan->id, 'recipe_id' => $a->id, 'servings' => 4]);
        MealPlanEntry::factory()->create(['meal_plan_id' => $plan->id, 'recipe_id' => $a->id, 'servings' => 2, 'meal_type' => 'lunch']);
        MealPlanEntry::factory()->create(['meal_plan_id' => $plan->id, 'recipe_id' => $b->id, 'servings' => 2]);

        $collected = app(PlanIngredientCollector::class)->collect($plan);
        $result = $this->consolidate($collected['lines']);

        $this->assertSame([], $collected['excluded_entry_ids']);
        $this->assertCount(1, $result);
        $this->assertSame('1.1000', $result[0]->quantity);
        $this->assertSame('kg', $result[0]->unit->code);
        $this->assertCount(3, $result[0]->sources);
    }

    public function test_collector_query_count_does_not_grow_with_the_number_of_recipes(): void
    {
        $user = User::factory()->create();
        $count = function (int $recipes) use ($user): int {
            $plan = MealPlan::factory()->create(['created_by' => $user->id, 'week_start' => MealPlan::weekStartFor(now())->addWeeks(random_int(10, 5000))->toDateString()]);

            foreach (range(1, $recipes) as $i) {
                $recipe = $this->recipeWithIngredient(Ingredient::factory()->create(), 'g', '100');
                MealPlanEntry::factory()->create(['meal_plan_id' => $plan->id, 'recipe_id' => $recipe->id, 'servings' => 3, 'planned_on' => $plan->week_start->toDateString()]);
            }

            DB::enableQueryLog();
            DB::flushQueryLog();
            app(PlanIngredientCollector::class)->collect($plan);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $small = $count(2);
        $large = $count(12);

        $this->assertSame($small, $large);
    }

    public function test_collector_excludes_recipes_the_plan_audience_cannot_view(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $flour = Ingredient::factory()->create();
        $household = Household::factory()->ownedBy($owner)->create();
        $household->members()->create(['user_id' => $member->id, 'role' => 'member']);
        $plan = MealPlan::factory()->create(['household_id' => $household->id, 'created_by' => $owner->id]);

        $private = $this->recipeWithIngredient($flour, 'g', '100', false);
        $private->forceFill(['author_id' => $owner->id, 'visibility' => 'private'])->saveQuietly();
        $public = $this->recipeWithIngredient($flour, 'g', '50');

        $privateEntry = MealPlanEntry::factory()->create(['meal_plan_id' => $plan->id, 'recipe_id' => $private->id, 'servings' => 2]);
        MealPlanEntry::factory()->create(['meal_plan_id' => $plan->id, 'recipe_id' => $public->id, 'servings' => 2, 'meal_type' => 'lunch']);

        $collected = app(PlanIngredientCollector::class)->collect($plan);

        $this->assertSame([$privateEntry->id], $collected['excluded_entry_ids']);
        $this->assertCount(1, $collected['lines']);
        $this->assertSame('50.0000', $this->consolidate($collected['lines'])[0]->quantity);
    }

    public function test_soft_deleted_recipes_are_excluded(): void
    {
        $user = User::factory()->create();
        $plan = MealPlan::factory()->create(['created_by' => $user->id]);
        $recipe = $this->recipeWithIngredient(Ingredient::factory()->create(), 'g', '100');
        $entry = MealPlanEntry::factory()->create(['meal_plan_id' => $plan->id, 'recipe_id' => $recipe->id]);
        $recipe->delete();

        $collected = app(PlanIngredientCollector::class)->collect($plan->fresh());

        $this->assertSame([], $collected['lines']);
        $this->assertContains($entry->id, $collected['excluded_entry_ids']);
    }
}
