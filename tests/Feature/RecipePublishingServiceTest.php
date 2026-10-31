<?php

namespace Tests\Feature;

use App\Models\Recipe;
use App\Services\RecipePublishingService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RecipePublishingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_bilingual_draft_is_published_with_a_timestamp(): void
    {
        $this->freezeTime();
        $recipe = Recipe::factory()->complete()->create();

        $publishedRecipe = app(RecipePublishingService::class)->publish($recipe);
        $freshRecipe = $publishedRecipe->fresh();

        $this->assertSame('published', $freshRecipe->status);
        $this->assertSame(now()->toDateTimeString(), $freshRecipe->published_at->toDateTimeString());
    }

    public function test_draft_without_both_translations_remains_unpublished(): void
    {
        $recipe = Recipe::factory()->create();
        $recipe->translateOrNew('en')->fill([
            'title' => 'Lemon soup',
            'slug' => 'lemon-soup',
        ]);
        $recipe->save();

        try {
            app(RecipePublishingService::class)->publish($recipe);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('translations', $exception->errors());
        }

        $this->assertDatabaseHas('recipes', [
            'id' => $recipe->id,
            'status' => 'draft',
            'published_at' => null,
        ]);
    }

    public function test_draft_with_nonpositive_servings_cannot_be_published(): void
    {
        $recipe = Recipe::factory()->make(['base_servings' => 0]);
        $recipe->translateOrNew('en')->fill([
            'title' => 'Lemon soup',
            'slug' => 'lemon-soup',
        ]);
        $recipe->translateOrNew('el')->fill([
            'title' => 'Σούπα λεμονιού',
            'slug' => 'soupa-lemoniou',
        ]);

        try {
            app(RecipePublishingService::class)->publish($recipe);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('base_servings', $exception->errors());
        }

        $this->assertFalse($recipe->exists);
    }

    public function test_database_rejects_a_published_recipe_without_a_timestamp(): void
    {
        $recipe = Recipe::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('recipes')
            ->where('id', $recipe->id)
            ->update(['status' => 'published', 'published_at' => null]);
    }

    public function test_database_rejects_nonpositive_base_servings(): void
    {
        $recipe = Recipe::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('recipes')
            ->where('id', $recipe->id)
            ->update(['base_servings' => 0]);
    }
}
