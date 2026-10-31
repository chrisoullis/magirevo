<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\IngredientGroupTranslation;
use App\Models\IngredientTranslation;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeIngredientTranslation;
use App\Models\Unit;
use App\Models\UnitTranslation;
use App\Services\ServingAdjustmentService;
use Database\Seeders\UnitSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class RecipeIngredientTest extends TestCase
{
    use RefreshDatabase;

    public function test_units_and_ingredient_groups_save_bilingual_translations(): void
    {
        $unit = Unit::factory()->create(['code' => 'cup']);
        $unit->translations()->createMany([
            ['locale' => 'en', 'name' => 'cup', 'abbreviation' => 'cup'],
            ['locale' => 'el', 'name' => 'φλιτζάνι', 'abbreviation' => 'φλ.'],
        ]);
        $ingredient = Ingredient::factory()->create();
        $ingredient->translations()->createMany([
            ['locale' => 'en', 'name' => 'Olive oil'],
            ['locale' => 'el', 'name' => 'Ελαιόλαδο'],
        ]);
        $recipe = Recipe::factory()->create();
        $group = $recipe->ingredientGroups()->create(['sort_order' => 1]);
        $group->translations()->createMany([
            ['locale' => 'en', 'name' => 'For the dressing'],
            ['locale' => 'el', 'name' => 'Για το ντρέσινγκ'],
        ]);
        $line = $recipe->recipeIngredients()->create([
            'ingredient_id' => $ingredient->id,
            'ingredient_group_id' => $group->id,
            'quantity' => '1.0000',
            'unit_id' => $unit->id,
        ]);
        $line->translations()->createMany([
            ['locale' => 'en', 'preparation_note' => 'finely chopped', 'quantity_text' => null],
            ['locale' => 'el', 'preparation_note' => 'ψιλοκομμένο', 'quantity_text' => null],
        ]);
        $previousLocale = app()->getLocale();

        try {
            app()->setLocale('el');

            $this->assertSame('φλιτζάνι', $unit->fresh()->name);
            $this->assertSame('Ελαιόλαδο', $ingredient->fresh()->name);
            $this->assertSame('Για το ντρέσινγκ', $group->fresh()->name);
            $this->assertSame('ψιλοκομμένο', $line->fresh()->preparation_note);
        } finally {
            app()->setLocale($previousLocale);
        }

        $this->assertTrue($recipe->recipeIngredients()->whereKey($line->id)->exists());
        $this->assertSame($recipe->id, $group->fresh()->recipe_id);
        $this->assertTrue($line->fresh()->ingredient->is($ingredient));
        $this->assertTrue($line->fresh()->ingredientGroup->is($group));
        $this->assertTrue($line->fresh()->unit->is($unit));
    }

    public function test_seeded_metric_units_use_canonical_metric_factors_in_both_languages(): void
    {
        $this->seed(UnitSeeder::class);

        $kilogram = Unit::query()->where('code', 'kg')->firstOrFail();
        $teaspoon = Unit::query()->where('code', 'tsp')->firstOrFail();

        $this->assertSame('1000.00000000', $kilogram->base_conversion);
        $this->assertSame('mass', $kilogram->unit_type);
        $this->assertSame('κιλό', $kilogram->translate('el')->name);
        $this->assertSame('5.00000000', $teaspoon->base_conversion);
        $this->assertSame('volume', $teaspoon->unit_type);
        $this->assertSame(7, Unit::query()->count());
        $this->assertSame(14, DB::table('unit_translations')->count());

        $this->seed(UnitSeeder::class);

        $this->assertSame(7, Unit::query()->count());
        $this->assertSame(14, DB::table('unit_translations')->count());
    }

    public function test_translation_factories_create_records_for_each_stage_two_entity(): void
    {
        $unitTranslation = UnitTranslation::factory()->create();
        $ingredientTranslation = IngredientTranslation::factory()->create();
        $ingredientGroupTranslation = IngredientGroupTranslation::factory()->create();
        $recipeIngredientTranslation = RecipeIngredientTranslation::factory()->create();

        $this->assertModelExists($unitTranslation);
        $this->assertModelExists($ingredientTranslation);
        $this->assertModelExists($ingredientGroupTranslation);
        $this->assertModelExists($recipeIngredientTranslation);
    }

    public function test_recipe_can_use_the_same_ingredient_more_than_once(): void
    {
        $recipe = Recipe::factory()->create();
        $ingredient = Ingredient::factory()->create();

        $recipe->recipeIngredients()->createMany([
            ['ingredient_id' => $ingredient->id, 'quantity' => '1.0000'],
            ['ingredient_id' => $ingredient->id, 'quantity' => '2.0000'],
        ]);

        $this->assertSame(2, $recipe->recipeIngredients()->where('ingredient_id', $ingredient->id)->count());
    }

    public function test_recipe_ingredient_rejects_a_group_owned_by_another_recipe(): void
    {
        $recipe = Recipe::factory()->create();
        $otherRecipe = Recipe::factory()->create();
        $otherGroup = $otherRecipe->ingredientGroups()->create();
        $ingredient = Ingredient::factory()->create();

        $this->expectException(QueryException::class);

        $recipe->recipeIngredients()->create([
            'ingredient_id' => $ingredient->id,
            'ingredient_group_id' => $otherGroup->id,
            'quantity' => '1.0000',
        ]);
    }

    public function test_recipe_ingredient_rejects_an_invalid_scaling_mode(): void
    {
        $recipe = Recipe::factory()->create();
        $ingredient = Ingredient::factory()->create();

        $this->expectException(QueryException::class);

        $recipe->recipeIngredients()->create([
            'ingredient_id' => $ingredient->id,
            'scaling_mode' => 'automatic',
        ]);
    }

    public function test_recipe_ingredient_rejects_a_quantity_range_below_its_minimum(): void
    {
        $recipe = Recipe::factory()->create();
        $ingredient = Ingredient::factory()->create();

        $this->expectException(QueryException::class);

        $recipe->recipeIngredients()->create([
            'ingredient_id' => $ingredient->id,
            'quantity' => '2.0000',
            'quantity_max' => '1.0000',
        ]);
    }

    public function test_recipe_ingredient_rejects_a_negative_quantity(): void
    {
        $recipe = Recipe::factory()->create();
        $ingredient = Ingredient::factory()->create();

        $this->expectException(QueryException::class);

        $recipe->recipeIngredients()->create([
            'ingredient_id' => $ingredient->id,
            'quantity' => '-0.0100',
        ]);
    }

    public function test_shared_ingredients_cannot_be_deleted_while_recipe_ingredients_use_them(): void
    {
        $ingredient = Ingredient::factory()->create();
        RecipeIngredient::factory()->create(['ingredient_id' => $ingredient->id]);

        $this->expectException(QueryException::class);

        $ingredient->delete();
    }

    public function test_linear_recipe_quantities_and_ranges_scale_with_decimal_precision(): void
    {
        $recipe = Recipe::factory()->create(['base_servings' => '2.00']);
        $recipeIngredient = RecipeIngredient::factory()->create([
            'recipe_id' => $recipe->id,
            'quantity' => '1.0001',
            'quantity_max' => '1.5000',
        ]);

        $adjusted = app(ServingAdjustmentService::class)->adjust($recipeIngredient, '3');

        $this->assertSame([
            'quantity' => '1.5002',
            'quantity_max' => '2.2500',
            'was_scaled' => true,
        ], $adjusted);
        $this->assertSame('1.0001', $recipeIngredient->fresh()->quantity);
        $this->assertSame('1.5000', $recipeIngredient->fresh()->quantity_max);
    }

    public function test_zero_linear_quantity_remains_zero_when_scaled(): void
    {
        $recipe = Recipe::factory()->create(['base_servings' => '2.00']);
        $recipeIngredient = RecipeIngredient::factory()->create([
            'recipe_id' => $recipe->id,
            'quantity' => '0.0000',
        ]);

        $adjusted = app(ServingAdjustmentService::class)->adjust($recipeIngredient, 4);

        $this->assertSame('0.0000', $adjusted['quantity']);
    }

    public function test_fixed_recipe_quantities_do_not_scale(): void
    {
        $recipeIngredient = RecipeIngredient::factory()->create([
            'quantity' => '1.2500',
            'quantity_max' => '2.5000',
            'scaling_mode' => 'fixed',
        ]);

        $adjusted = app(ServingAdjustmentService::class)->adjust($recipeIngredient, 8);

        $this->assertSame([
            'quantity' => '1.2500',
            'quantity_max' => '2.5000',
            'was_scaled' => false,
        ], $adjusted);
    }

    public function test_manual_recipe_quantities_do_not_scale(): void
    {
        $recipeIngredient = RecipeIngredient::factory()->create([
            'quantity' => null,
            'quantity_max' => null,
            'scaling_mode' => 'manual',
        ]);

        $adjusted = app(ServingAdjustmentService::class)->adjust($recipeIngredient, 8);

        $this->assertSame([
            'quantity' => null,
            'quantity_max' => null,
            'was_scaled' => false,
        ], $adjusted);
    }

    public function test_serving_adjustment_rejects_zero_requested_servings(): void
    {
        $recipeIngredient = RecipeIngredient::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Requested servings must be a positive decimal number.');

        app(ServingAdjustmentService::class)->adjust($recipeIngredient, '0.00');
    }
}
