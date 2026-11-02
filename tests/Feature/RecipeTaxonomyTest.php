<?php

namespace Tests\Feature;

use App\Enums\TagClassification;
use App\Models\Category;
use App\Models\CategoryTranslation;
use App\Models\Recipe;
use App\Models\Tag;
use App\Models\TagTranslation;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DifficultySeeder;
use Database\Seeders\RecipeSeeder;
use Database\Seeders\TagSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecipeTaxonomyTest extends TestCase
{
    use RefreshDatabase;

    public function test_categories_and_tags_resolve_bilingual_translations(): void
    {
        $category = Category::factory()->create();
        $category->translations()->createMany([
            ['locale' => 'en', 'name' => 'Weeknight', 'slug' => 'weeknight-'.$category->getKey()],
            ['locale' => 'el', 'name' => 'Καθημερινά', 'slug' => 'kathimerina-'.$category->getKey()],
        ]);
        $tag = Tag::factory()->create();
        $tag->translations()->createMany([
            ['locale' => 'en', 'name' => 'Quick', 'slug' => 'quick-'.$tag->getKey()],
            ['locale' => 'el', 'name' => 'Γρήγορα', 'slug' => 'grigora-'.$tag->getKey()],
        ]);
        $previousLocale = app()->getLocale();

        try {
            app()->setLocale('en');
            $this->assertSame('Weeknight', $category->fresh()->name);
            $this->assertSame('Quick', $tag->fresh()->name);

            app()->setLocale('el');
            $this->assertSame('Καθημερινά', $category->fresh()->name);
            $this->assertSame('Γρήγορα', $tag->fresh()->name);
        } finally {
            app()->setLocale($previousLocale);
        }
    }

    public function test_recipe_category_and_tag_relationships_work_in_both_directions(): void
    {
        $recipe = Recipe::factory()->create();
        $category = Category::factory()->create();
        $tag = Tag::factory()->create();

        $recipe->categories()->attach($category);
        $recipe->tags()->attach($tag);

        $this->assertTrue($recipe->fresh()->categories->contains($category));
        $this->assertTrue($category->fresh()->recipes->contains($recipe));
        $this->assertTrue($recipe->fresh()->tags->contains($tag));
        $this->assertTrue($tag->fresh()->recipes->contains($recipe));
        $this->assertDatabaseHas('recipe_category', [
            'recipe_id' => $recipe->id,
            'category_id' => $category->id,
        ]);
        $this->assertDatabaseHas('recipe_tag', [
            'recipe_id' => $recipe->id,
            'tag_id' => $tag->id,
        ]);
    }

    public function test_category_and_tag_translation_factories_create_translated_records(): void
    {
        $categoryTranslation = CategoryTranslation::factory()->create();
        $tagTranslation = TagTranslation::factory()->create();

        $this->assertModelExists($categoryTranslation);
        $this->assertModelExists($tagTranslation);
    }

    public function test_taxonomy_seeders_create_idempotent_bilingual_records(): void
    {
        $this->seed([CategorySeeder::class, TagSeeder::class]);

        $this->assertSame(68, Category::query()->count());
        $this->assertSame(136, DB::table('category_translations')->count());
        $this->assertSame(6, Tag::query()->count());
        $this->assertSame(12, DB::table('tag_translations')->count());
        $this->assertSame('Χορτοφαγικά', Tag::query()->where('code', 'vegetarian')->firstOrFail()->translate('el')->name);

        $this->seed([CategorySeeder::class, TagSeeder::class]);

        $this->assertSame(68, Category::query()->count());
        $this->assertSame(136, DB::table('category_translations')->count());
        $this->assertSame(6, Tag::query()->count());
        $this->assertSame(12, DB::table('tag_translations')->count());
    }

    public function test_tag_seeder_preserves_existing_classification_and_translations(): void
    {
        $tag = Tag::factory()->create([
            'code' => 'vegetarian',
            'classification' => TagClassification::DietStyle,
            'is_public_enabled' => false,
            'is_active' => false,
            'sort_order' => 99,
        ]);
        $tag->translations()->createMany([
            ['locale' => 'en', 'name' => 'Reviewed vegetarian label', 'slug' => 'reviewed-vegetarian'],
            ['locale' => 'el', 'name' => 'Ελεγμένη χορτοφαγική ετικέτα', 'slug' => 'elegmeni-chortofagiki'],
        ]);

        $this->seed(TagSeeder::class);

        $tag->refresh();
        $vegan = Tag::query()->where('code', 'vegan')->firstOrFail();

        $this->assertSame(TagClassification::DietStyle, $tag->classification);
        $this->assertFalse($tag->is_public_enabled);
        $this->assertFalse($tag->is_active);
        $this->assertSame(99, $tag->sort_order);
        $this->assertSame('Reviewed vegetarian label', $tag->translate('en')->name);
        $this->assertSame('Ελεγμένη χορτοφαγική ετικέτα', $tag->translate('el')->name);
        $this->assertSame(TagClassification::DietStyle, $vegan->classification);
        $this->assertSame('Vegan', $vegan->translate('en')->name);
        $this->assertSame('Vegan', $vegan->translate('el')->name);
    }

    public function test_recipe_seeder_links_seeded_categories_and_attaches_a_remote_cover(): void
    {
        $this->seed(CategorySeeder::class);
        $this->seed(TagSeeder::class);
        $this->seed(DifficultySeeder::class);
        $this->seed(RecipeSeeder::class);

        $recipe = Recipe::query()
            ->whereTranslation('slug', 'crispy-chickpeas-herby-yogurt', 'en')
            ->firstOrFail();

        $this->assertSame('remote', $recipe->coverMedia()->disk);
        $this->assertTrue($recipe->categories()->where('code', 'legume_dishes')->exists());
        $this->assertTrue($recipe->tags()->where('code', 'vegetarian')->exists());
    }

    public function test_category_translations_reject_duplicate_locale_slugs(): void
    {
        $firstCategory = Category::factory()->create();
        $firstCategory->translations()->create([
            'locale' => 'en',
            'name' => 'First',
            'slug' => 'shared-category',
        ]);
        $secondCategory = Category::factory()->create();

        $this->expectException(QueryException::class);

        $secondCategory->translations()->create([
            'locale' => 'en',
            'name' => 'Second',
            'slug' => 'shared-category',
        ]);
    }

    public function test_tag_translations_reject_duplicate_locale_slugs(): void
    {
        $firstTag = Tag::factory()->create();
        $firstTag->translations()->create([
            'locale' => 'en',
            'name' => 'First',
            'slug' => 'shared-tag',
        ]);
        $secondTag = Tag::factory()->create();

        $this->expectException(QueryException::class);

        $secondTag->translations()->create([
            'locale' => 'en',
            'name' => 'Second',
            'slug' => 'shared-tag',
        ]);
    }

    public function test_category_translations_reject_duplicate_locales_for_one_category(): void
    {
        $category = Category::factory()->create();
        $category->translations()->create([
            'locale' => 'en',
            'name' => 'First',
            'slug' => 'first-category',
        ]);

        $this->expectException(QueryException::class);

        $category->translations()->create([
            'locale' => 'en',
            'name' => 'Second',
            'slug' => 'second-category',
        ]);
    }

    public function test_tag_translations_reject_duplicate_locales_for_one_tag(): void
    {
        $tag = Tag::factory()->create();
        $tag->translations()->create([
            'locale' => 'en',
            'name' => 'First',
            'slug' => 'first-tag',
        ]);

        $this->expectException(QueryException::class);

        $tag->translations()->create([
            'locale' => 'en',
            'name' => 'Second',
            'slug' => 'second-tag',
        ]);
    }

    public function test_category_translations_reject_unsupported_locales(): void
    {
        $category = Category::factory()->create();

        $this->expectException(QueryException::class);

        $category->translations()->create([
            'locale' => 'fr',
            'name' => 'Catégorie',
            'slug' => 'categorie',
        ]);
    }

    public function test_tag_translations_reject_unsupported_locales(): void
    {
        $tag = Tag::factory()->create();

        $this->expectException(QueryException::class);

        $tag->translations()->create([
            'locale' => 'fr',
            'name' => 'Étiquette',
            'slug' => 'etiquette',
        ]);
    }

    public function test_recipe_category_pivot_rejects_duplicate_associations(): void
    {
        $recipe = Recipe::factory()->create();
        $category = Category::factory()->create();
        $recipe->categories()->attach($category);

        $this->expectException(QueryException::class);

        $recipe->categories()->attach($category);
    }

    public function test_recipe_tag_pivot_rejects_duplicate_associations(): void
    {
        $recipe = Recipe::factory()->create();
        $tag = Tag::factory()->create();
        $recipe->tags()->attach($tag);

        $this->expectException(QueryException::class);

        $recipe->tags()->attach($tag);
    }
}
