<?php

namespace Tests\Feature;

use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Livewire\RecipeIndex;
use App\Models\Category;
use App\Models\Recipe;
use App\Models\User;
use App\Services\CategoryHierarchyService;
use Database\Seeders\CategorySeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class CategoryTaxonomyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeCategory(string $code, ?Category $parent = null, array $attributes = []): Category
    {
        $category = Category::factory()->create([
            'code' => $code,
            'parent_id' => $parent?->getKey(),
            ...$attributes,
        ]);
        $category->translations()->createMany([
            ['locale' => 'en', 'name' => ucfirst($code), 'slug' => "{$code}-en"],
            ['locale' => 'el', 'name' => "Κατηγορία {$code}", 'slug' => "{$code}-el"],
        ]);

        return $category;
    }

    private function publishedRecipe(Category ...$categories): Recipe
    {
        $recipe = Recipe::factory()->published()->create();
        $recipe->categories()->attach(collect($categories)->map->getKey());

        return $recipe;
    }

    private function requireFilamentRendering(): void
    {
        if (! extension_loaded('intl')) {
            $this->markTestSkipped('Filament page rendering requires the PHP intl extension.');
        }

        Filament::setCurrentPanel('admin');
    }

    public function test_seeder_creates_the_full_bilingual_taxonomy(): void
    {
        $this->seed(CategorySeeder::class);

        $this->assertSame(68, Category::query()->count());
        $this->assertSame(12, Category::query()->whereNull('parent_id')->count());
        $this->assertSame(56, Category::query()->whereNotNull('parent_id')->count());
        $this->assertSame(0, Category::query()->doesntHave('translations')->count());

        foreach (['en', 'el'] as $locale) {
            $this->assertSame(68, DB::table('category_translations')->where('locale', $locale)->count());
            $this->assertSame(68, DB::table('category_translations')->where('locale', $locale)->distinct()->count('slug'));
        }

        $this->assertSame(68, Category::query()->distinct()->count('code'));
        $this->assertSame(0, Category::query()->where('code', '!~', '^[a-z0-9]+(_[a-z0-9]+)*$')->count());

        $soups = Category::query()->where('code', 'creamy_soups')->firstOrFail();
        $this->assertSame('soups', $soups->parent->code);
        $this->assertSame('Σούπες Βελουτέ', $soups->getTranslation('el', false)->name);
        $this->assertSame('Creamy Soups', $soups->getTranslation('en', false)->name);
    }

    public function test_reseeding_is_idempotent_and_preserves_administrator_changes(): void
    {
        $this->seed(CategorySeeder::class);
        $soups = Category::query()->where('code', 'soups')->firstOrFail();
        $recipe = $this->publishedRecipe($soups);

        $soups->translate('en')->update(['name' => 'Hearty soups', 'slug' => 'hearty-soups']);
        $soups->update(['sort_order' => 999, 'is_active' => false]);

        $this->seed(CategorySeeder::class);

        $this->assertSame(68, Category::query()->count());
        $this->assertSame(136, DB::table('category_translations')->count());

        $soups->refresh();
        $this->assertSame('Hearty soups', $soups->getTranslation('en', false)->name);
        $this->assertSame('hearty-soups', $soups->getTranslation('en', false)->slug);
        $this->assertSame(999, $soups->sort_order);
        $this->assertFalse($soups->is_active);
        $this->assertTrue($recipe->categories()->whereKey($soups->getKey())->exists());
    }

    public function test_restore_defaults_resets_the_taxonomy_but_keeps_other_categories_and_recipes(): void
    {
        $this->seed(CategorySeeder::class);
        $custom = $this->makeCategory('custom_category');
        $soups = Category::query()->where('code', 'soups')->firstOrFail();
        $soups->translate('en')->update(['name' => 'Changed']);
        $soups->update(['sort_order' => 999]);
        $recipe = $this->publishedRecipe($soups);

        $this->artisan('categories:restore-defaults --no-interaction')->assertSuccessful();

        $this->assertModelExists($custom);
        $this->assertSame('Soups', $soups->fresh()->getTranslation('en', false)->name);
        $this->assertSame(30, $soups->fresh()->sort_order);
        $this->assertTrue($recipe->categories()->whereKey($soups->getKey())->exists());
    }

    public function test_restore_defaults_is_unavailable_outside_local_environments(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('categories:restore-defaults --no-interaction')->assertFailed();
    }

    public function test_root_and_child_sorting_are_independent(): void
    {
        $this->seed(CategorySeeder::class);
        $service = app(CategoryHierarchyService::class);

        $roots = Category::query()->roots()->ordered()->pluck('code')->take(3)->all();
        $this->assertSame(['breakfast_brunch', 'appetizers_snacks', 'soups'], $roots);

        $mains = Category::query()->where('code', 'main_dishes')->firstOrFail();
        $this->assertSame('chicken_poultry', $mains->children()->get()->first()->code);

        $service->shift($mains->children()->get()->first(), 1);

        $this->assertSame('beef_veal', $mains->children()->get()->first()->code);
        $this->assertSame($roots, Category::query()->roots()->ordered()->pluck('code')->take(3)->all());

        $service->shift(Category::query()->where('code', 'soups')->firstOrFail(), -1);

        $this->assertSame(['breakfast_brunch', 'soups', 'appetizers_snacks'], Category::query()->roots()->ordered()->pluck('code')->take(3)->all());
        $this->assertSame('beef_veal', $mains->children()->get()->first()->code);
    }

    public function test_reordering_rejects_categories_from_another_parent(): void
    {
        $this->seed(CategorySeeder::class);
        $soups = Category::query()->where('code', 'soups')->firstOrFail();
        $stray = Category::query()->where('code', 'pork')->firstOrFail();

        $this->expectException(ValidationException::class);

        app(CategoryHierarchyService::class)->reorderSiblings($soups->getKey(), [$stray->getKey()]);
    }

    public function test_new_categories_are_appended_after_their_siblings(): void
    {
        $this->seed(CategorySeeder::class);
        $parent = Category::query()->where('code', 'soups')->firstOrFail();

        $child = Category::query()->create(['code' => 'new_soup', 'parent_id' => $parent->getKey()]);

        $this->assertSame(50, $child->sort_order);
    }

    public function test_a_third_level_is_rejected(): void
    {
        $root = $this->makeCategory('root_one');
        $child = $this->makeCategory('child_one', $root);

        $this->expectException(ValidationException::class);

        $this->makeCategory('grandchild_one', $child);
    }

    public function test_circular_and_self_references_are_rejected(): void
    {
        $root = $this->makeCategory('root_one');
        $child = $this->makeCategory('child_one', $root);

        foreach ([[$root, $root], [$root, $child]] as [$category, $parent]) {
            try {
                $category->update(['parent_id' => $parent->getKey()]);
                $this->fail('Expected a hierarchy violation.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('parent_id', $exception->errors());
            }
        }

        $this->assertNull($root->fresh()->parent_id);
    }

    public function test_a_category_with_children_cannot_become_a_subcategory(): void
    {
        $root = $this->makeCategory('root_one');
        $this->makeCategory('child_one', $root);
        $other = $this->makeCategory('root_two');

        $this->expectException(ValidationException::class);

        $root->update(['parent_id' => $other->getKey()]);
    }

    public function test_a_childless_category_can_move_under_another_root(): void
    {
        $root = $this->makeCategory('root_one');
        $other = $this->makeCategory('root_two');

        $moved = app(CategoryHierarchyService::class)->moveTo($other, $root);

        $this->assertSame($root->getKey(), $moved->parent_id);
    }

    public function test_the_category_code_cannot_be_changed_after_creation(): void
    {
        $category = $this->makeCategory('stable_code');

        $this->expectException(ValidationException::class);

        $category->update(['code' => 'renamed_code']);
    }

    public function test_inactive_categories_and_children_of_inactive_parents_are_hidden_publicly(): void
    {
        $parent = $this->makeCategory('visible_parent');
        $child = $this->makeCategory('visible_child', $parent);
        $inactive = $this->makeCategory('inactive_root', attributes: ['is_active' => false]);
        $recipe = $this->publishedRecipe($parent, $child, $inactive);

        $this->get('/en/categories/visible_parent-en')->assertOk();
        $this->get('/en/categories/visible_child-en')->assertOk();
        $this->get('/en/categories/inactive_root-en')->assertNotFound();

        $parent->update(['is_active' => false]);

        $this->assertTrue($child->fresh()->is_active);
        $this->get('/en/categories/visible_parent-en')->assertNotFound();
        $this->get('/en/categories/visible_child-en')->assertNotFound();
        $this->assertSame(0, Category::query()->publiclyVisible()->whereIn('code', ['visible_parent', 'visible_child'])->count());
        $this->assertSame(3, $recipe->categories()->count());

        $parent->update(['is_active' => true]);

        $this->get('/en/categories/visible_child-en')->assertOk();
    }

    public function test_public_navigation_lists_nested_non_empty_visible_categories_only(): void
    {
        $parent = $this->makeCategory('nav_parent', attributes: ['sort_order' => 20]);
        $filled = $this->makeCategory('nav_filled', $parent, ['sort_order' => 10]);
        $this->makeCategory('nav_empty', $parent, ['sort_order' => 20]);
        $this->makeCategory('empty_root', attributes: ['sort_order' => 10]);
        $this->publishedRecipe($filled);

        $this->get('/en')->assertOk()->assertSee('Nav_parent')->assertDontSee('Empty_root');

        Livewire::withQueryParams(['category' => 'nav_parent'])
            ->test(RecipeIndex::class, ['locale' => 'en'])
            ->assertSee('Nav_filled')
            ->assertDontSee('Nav_empty');
    }

    public function test_recipe_counts_are_accurate_and_parent_totals_are_distinct(): void
    {
        $parent = $this->makeCategory('count_parent');
        $childA = $this->makeCategory('count_a', $parent);
        $childB = $this->makeCategory('count_b', $parent);

        $this->publishedRecipe($parent);
        $this->publishedRecipe($childA);
        $this->publishedRecipe($childA, $childB, $parent);
        $this->publishedRecipe($childB);

        $counts = Category::query()->withRecipeCounts()->get()->keyBy('code');

        $this->assertSame(2, (int) $counts['count_parent']->direct_recipes_count);
        $this->assertSame(4, (int) $counts['count_parent']->total_recipes_count);
        $this->assertSame(2, (int) $counts['count_a']->direct_recipes_count);
        $this->assertSame(2, (int) $counts['count_a']->total_recipes_count);
        $this->assertSame(2, (int) $counts['count_b']->total_recipes_count);
    }

    public function test_public_counts_exclude_drafts_archived_and_soft_deleted_recipes(): void
    {
        $parent = $this->makeCategory('pub_parent');
        $child = $this->makeCategory('pub_child', $parent);

        $this->publishedRecipe($child);
        $draft = Recipe::factory()->create();
        $archived = Recipe::factory()->create(['status' => 'archived']);
        $deleted = $this->publishedRecipe($child, $parent);
        foreach ([$draft, $archived] as $recipe) {
            $recipe->categories()->attach([$parent->getKey(), $child->getKey()]);
        }
        $deleted->delete();

        $public = Category::query()->withRecipeCounts(publishedOnly: true)->get()->keyBy('code');
        $all = Category::query()->withRecipeCounts()->get()->keyBy('code');

        $this->assertSame(1, (int) $public['pub_parent']->published_total_recipes_count);
        $this->assertSame(1, (int) $public['pub_child']->published_direct_recipes_count);
        $this->assertSame(0, (int) $public['pub_parent']->published_direct_recipes_count);
        $this->assertSame(3, (int) $all['pub_parent']->total_recipes_count);
    }

    public function test_parent_pages_include_child_recipes_and_urls_resolve_in_both_locales(): void
    {
        $parent = $this->makeCategory('url_parent');
        $child = $this->makeCategory('url_child', $parent);
        $recipe = $this->publishedRecipe($child);

        $this->get('/en/categories/url_parent-en')->assertOk()->assertSee('Url_child');
        $this->get('/el/categories/url_parent-el')->assertOk()->assertSee('Κατηγορία url_parent');
        $this->get('/en/categories/url_parent-el')->assertNotFound();

        $this->assertTrue(Recipe::query()->published()->inCategory('url_parent')->whereKey($recipe->getKey())->exists());
        $this->assertFalse(Recipe::query()->published()->inCategory('url_child')->whereKey(Recipe::factory()->create()->getKey())->exists());

        $this->get('/en/categories/url_child-en')
            ->assertOk()
            ->assertSee('/en/categories/url_parent-en', false);
    }

    public function test_existing_recipe_category_associations_stay_valid_when_the_hierarchy_changes(): void
    {
        $root = $this->makeCategory('assoc_root');
        $other = $this->makeCategory('assoc_other');
        $recipe = $this->publishedRecipe($other);

        app(CategoryHierarchyService::class)->moveTo($other, $root);
        $other->update(['is_active' => false]);

        $this->assertTrue($recipe->fresh()->categories->contains($other));
        $this->assertSame(1, DB::table('recipe_category')->where('category_id', $other->getKey())->count());
    }

    public function test_admin_pages_enforce_roles(): void
    {
        $this->requireFilamentRendering();
        $category = $this->makeCategory('role_check');
        $member = User::factory()->create();
        $editor = User::factory()->editor()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($member)->get('/admin/categories')->assertForbidden();

        $this->actingAs($editor)->get('/admin/categories')->assertOk();
        $this->actingAs($editor)->get("/admin/categories/{$category->getKey()}")->assertOk();
        $this->actingAs($editor)->get('/admin/categories/create')->assertForbidden();
        $this->actingAs($editor)->get("/admin/categories/{$category->getKey()}/edit")->assertForbidden();

        $this->actingAs($admin)->get('/admin/categories/create')->assertOk();
        $this->actingAs($admin)->get("/admin/categories/{$category->getKey()}/edit")->assertOk();
    }

    public function test_admin_list_shows_hierarchy_and_counts_and_runs_scoped_actions(): void
    {
        $this->requireFilamentRendering();
        $parent = $this->makeCategory('list_parent', attributes: ['sort_order' => 10]);
        $first = $this->makeCategory('list_first', $parent, ['sort_order' => 10]);
        $second = $this->makeCategory('list_second', $parent, ['sort_order' => 20]);
        $this->publishedRecipe($first, $second);
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)
            ->test(ListCategories::class)
            ->assertCanSeeTableRecords([$parent, $first, $second], inOrder: true)
            ->assertTableColumnStateSet('total_recipes_count', 1, $parent)
            ->assertTableColumnStateSet('direct_recipes_count', 0, $parent)
            ->assertTableColumnStateSet('level', 'Subcategory', $first)
            ->callTableAction('moveDown', $first)
            ->callTableAction('toggleActive', $second);

        $this->assertSame(['list_second', 'list_first'], $parent->children()->pluck('code')->all());
        $this->assertFalse($second->fresh()->is_active);
    }

    public function test_category_form_rejects_invalid_parents(): void
    {
        $this->requireFilamentRendering();
        $root = $this->makeCategory('form_root');
        $child = $this->makeCategory('form_child', $root);
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)
            ->test(CreateCategory::class)
            ->fillForm([
                'code' => 'form_grandchild',
                'parent_id' => $child->getKey(),
                'en.name' => 'Grandchild',
                'en.slug' => 'grandchild',
                'el.name' => 'Εγγόνι',
                'el.slug' => 'eggoni',
            ])
            ->call('create')
            ->assertHasFormErrors(['parent_id']);

        $this->assertDatabaseMissing('categories', ['code' => 'form_grandchild']);
    }
}
