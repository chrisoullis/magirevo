<?php

namespace Tests\Feature;

use App\Models\Recipe;
use App\Services\RecipeContentGuard;
use App\Services\RecipeRevisionService;
use Database\Seeders\AdditionalUnitSeeder;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DifficultySeeder;
use Database\Seeders\RecipeContentSeeder;
use Database\Seeders\RecipeSeeder;
use Database\Seeders\ScalingModeSeeder;
use Database\Seeders\TagSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SeederProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([UnitSeeder::class, AdditionalUnitSeeder::class, CategorySeeder::class, TagSeeder::class, DifficultySeeder::class, ScalingModeSeeder::class]);
    }

    public function test_sample_recipes_are_not_seeded_while_the_guard_enforces(): void
    {
        app(RecipeContentGuard::class)->setMode('enforce');

        $this->seed(RecipeSeeder::class);
        $this->seed(RecipeContentSeeder::class);

        $this->assertSame(0, Recipe::query()->count());
    }

    public function test_sample_recipes_are_not_seeded_in_production(): void
    {
        $this->app['env'] = 'production';

        (new RecipeSeeder)->run();

        $this->assertSame(0, Recipe::query()->count());
    }

    public function test_reseeding_never_rewrites_a_live_recipe(): void
    {
        $this->seed(RecipeSeeder::class);
        $this->seed(RecipeContentSeeder::class);
        $recipe = Recipe::query()->has('recipeIngredients')->firstOrFail();
        $ingredientCount = $recipe->recipeIngredients()->count();
        app(RecipeRevisionService::class)->syncPublishedFromLive($recipe->id);
        DB::table('recipes')->where('id', $recipe->id)->update(['rest_minutes' => 17]);
        DB::table('recipe_ingredients')->where('id', $recipe->recipeIngredients()->min('id'))->delete();

        $this->seed(RecipeSeeder::class);
        $this->seed(RecipeContentSeeder::class);

        $this->assertSame(17, $recipe->fresh()->rest_minutes);
        $this->assertSame($ingredientCount - 1, $recipe->recipeIngredients()->count());
    }
}
