<?php

namespace Tests\Feature;

use App\Models\Recipe;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecipeTranslationTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_recipe_saves_and_retrieves_english_and_greek_translations(): void
    {
        $recipe = Recipe::factory()->create();
        $recipe->translateOrNew('en')->fill([
            'title' => 'Lemon soup',
            'slug' => 'lemon-soup',
        ]);
        $recipe->translateOrNew('el')->fill([
            'title' => 'Σούπα λεμονιού',
            'slug' => 'soupa-lemoniou',
        ]);
        $recipe->save();

        $translatedRecipe = $recipe->fresh();
        $previousLocale = app()->getLocale();

        try {
            app()->setLocale('en');
            $this->assertSame('Lemon soup', $translatedRecipe->title);

            app()->setLocale('el');
            $this->assertSame('Σούπα λεμονιού', $translatedRecipe->title);
        } finally {
            app()->setLocale($previousLocale);
        }
    }

    public function test_localized_slugs_must_be_unique_within_the_same_locale(): void
    {
        $firstRecipe = Recipe::factory()->create();
        $firstRecipe->translations()->create([
            'locale' => 'en',
            'title' => 'First recipe',
            'slug' => 'shared-slug',
        ]);

        $secondRecipe = Recipe::factory()->create();

        $this->expectException(QueryException::class);

        $secondRecipe->translations()->create([
            'locale' => 'en',
            'title' => 'Second recipe',
            'slug' => 'shared-slug',
        ]);
    }

    public function test_the_same_slug_can_be_used_in_different_locales(): void
    {
        $recipe = Recipe::factory()->create();
        $recipe->translations()->createMany([
            ['locale' => 'en', 'title' => 'Shared slug', 'slug' => 'shared-slug'],
            ['locale' => 'el', 'title' => 'Κοινό slug', 'slug' => 'shared-slug'],
        ]);

        $this->assertModelExists($recipe);
        $this->assertSame(2, $recipe->translations()->count());
    }

    public function test_missing_greek_translation_does_not_fall_back_to_english(): void
    {
        $recipe = Recipe::factory()->create();
        $recipe->translations()->create([
            'locale' => 'en',
            'title' => 'Lemon soup',
            'slug' => 'lemon-soup',
        ]);
        $previousLocale = app()->getLocale();

        try {
            app()->setLocale('el');

            $this->assertNull($recipe->fresh()->title);
        } finally {
            app()->setLocale($previousLocale);
        }
    }

    public function test_recipe_author_is_required(): void
    {
        $this->expectException(QueryException::class);

        Recipe::factory()->create(['author_id' => null]);
    }

    public function test_recipe_author_cannot_be_deleted_while_they_have_recipes(): void
    {
        $recipe = Recipe::factory()->create();

        $this->expectException(QueryException::class);

        $recipe->author->delete();
    }
}
