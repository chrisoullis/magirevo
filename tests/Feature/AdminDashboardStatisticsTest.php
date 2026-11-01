<?php

namespace Tests\Feature;

use App\Filament\Widgets\CategoryStatistics;
use App\Filament\Widgets\MostFavoritedRecipes;
use App\Filament\Widgets\RecipeActivityChart;
use App\Filament\Widgets\RecipeStatusChart;
use App\Filament\Widgets\UsersByRoleChart;
use App\Models\Category;
use App\Models\Recipe;
use App\Models\User;
use App\Services\DashboardStatisticsService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

class AdminDashboardStatisticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        Cache::flush();
    }

    private function stats(): DashboardStatisticsService
    {
        return app(DashboardStatisticsService::class);
    }

    private function recipe(array $attributes = [], string $title = 'Dish'): Recipe
    {
        $slug = str($title)->slug().'-'.fake()->unique()->numerify('####');
        $recipe = Recipe::factory()->make($attributes);
        $recipe->fill([
            'en' => ['title' => $title, 'slug' => $slug],
            'el' => ['title' => $title.' EL', 'slug' => $slug.'-el'],
        ])->save();

        return $recipe;
    }

    private function publicRecipe(array $attributes = [], string $title = 'Public Dish'): Recipe
    {
        return $this->recipe([...['published_at' => now()->subDay()], ...$attributes, 'status' => 'published', 'visibility' => 'public'], $title);
    }

    public function test_activity_chart_counts_per_day_and_respects_ranges(): void
    {
        $admin = User::factory()->admin()->create();
        $this->recipe(['created_at' => now()->subDays(2)]);
        $this->recipe(['created_at' => now()->subDays(2)]);
        $this->recipe(['created_at' => now()->subDays(20)]);
        $this->publicRecipe(['created_at' => now()->subDays(100), 'published_at' => now()->subDays(1)]);

        $week = $this->stats()->activity($admin, 7);
        $month = $this->stats()->activity($admin, 30);
        $quarter = $this->stats()->activity($admin, 90);

        $this->assertCount(7, $week['labels']);
        $this->assertCount(30, $month['labels']);
        $this->assertCount(90, $quarter['labels']);
        $this->assertSame(2, array_sum($week['created']));
        $this->assertSame(3, array_sum($month['created']));
        $this->assertSame(3, array_sum($quarter['created']));
        $this->assertSame(1, array_sum($week['published']));
    }

    public function test_unknown_activity_range_falls_back_to_thirty_days(): void
    {
        $this->assertCount(30, $this->stats()->activity(User::factory()->admin()->create(), 5)['labels']);
    }

    public function test_activity_excludes_other_users_private_recipes_for_editors(): void
    {
        $editor = User::factory()->editor()->create();
        $this->recipe(['status' => 'draft']);
        $this->recipe(['author_id' => $editor->id]);

        $this->assertSame(1, array_sum($this->stats()->activity($editor, 7)['created']));
    }

    public function test_activity_chart_switches_ranges_and_loads_lazily(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->assertTrue((new \ReflectionMethod(RecipeActivityChart::class, 'isLazyLoaded'))->invoke(null));
        Livewire::test(RecipeActivityChart::class)
            ->assertSet('filter', '30')
            ->set('filter', '7')
            ->assertSee(__('dashboard.activity.last_days', ['days' => 7]));
    }

    public function test_status_distribution_includes_every_status(): void
    {
        $admin = User::factory()->admin()->create();
        $this->recipe(['status' => 'draft']);
        $this->recipe(['status' => 'pending']);
        $this->recipe(['status' => 'pending']);

        $this->assertSame(['draft' => 1, 'pending' => 2, 'published' => 0, 'archived' => 0], $this->stats()->statusDistribution($admin));
    }

    public function test_users_by_role_is_admin_only_and_counts_each_role(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->editor()->create();
        User::factory()->contributor()->count(2)->create();
        User::factory()->create();

        $this->assertSame(['admin' => 1, 'editor' => 1, 'contributor' => 2, 'member' => 1], $this->stats()->usersByRole($admin));
        $this->assertNull($this->stats()->usersByRole(User::factory()->editor()->create()));
    }

    public function test_category_counts_use_distinct_recipes_for_parent_totals(): void
    {
        $admin = User::factory()->admin()->create();
        $parent = Category::factory()->create();
        $childA = Category::factory()->create(['parent_id' => $parent->id]);
        $childB = Category::factory()->create(['parent_id' => $parent->id]);

        $both = $this->recipe();
        $both->categories()->attach([$childA->id, $childB->id]);
        $onlyA = $this->recipe();
        $onlyA->categories()->attach([$childA->id, $parent->id]);

        $rows = $this->stats()->categoryCounts($admin)->get()->keyBy('id');

        $this->assertSame(2, (int) $rows[$parent->id]->dashboard_total_count);
        $this->assertSame(1, (int) $rows[$parent->id]->dashboard_direct_count);
        $this->assertSame(2, (int) $rows[$childA->id]->dashboard_direct_count);
        $this->assertSame(1, (int) $rows[$childB->id]->dashboard_direct_count);
    }

    public function test_category_counts_hide_private_recipes_from_editors(): void
    {
        $editor = User::factory()->editor()->create();
        $category = Category::factory()->create();
        $this->recipe()->categories()->attach($category);
        $this->recipe(['author_id' => $editor->id])->categories()->attach($category);
        $this->publicRecipe()->categories()->attach($category);

        $row = $this->stats()->categoryCounts($editor)->get()->firstWhere('id', $category->id);

        $this->assertSame(2, (int) $row->dashboard_total_count);
    }

    public function test_most_favorited_lists_only_public_live_recipes_in_order(): void
    {
        $admin = User::factory()->admin()->create();
        $fans = User::factory()->count(3)->create();
        $top = $this->publicRecipe([], 'Top Dish');
        $second = $this->publicRecipe([], 'Second Dish');
        $private = $this->recipe([], 'Private Favorite');
        $unloved = $this->publicRecipe([], 'Unloved Dish');
        $top->favoritedBy()->attach($fans->pluck('id'));
        $second->favoritedBy()->attach($fans[0]);
        $private->favoritedBy()->attach($fans[0]);

        $this->assertSame([$top->id, $second->id], $this->stats()->mostFavorited($admin)->pluck('recipes.id')->all());

        $this->actingAs($admin);
        Livewire::test(MostFavoritedRecipes::class)
            ->assertSee('Top Dish')
            ->assertDontSee('Private Favorite')
            ->assertDontSee('Unloved Dish');
    }

    public function test_stage_two_widgets_are_authorized_and_translated(): void
    {
        $this->actingAs(User::factory()->editor()->create());
        $this->assertTrue(RecipeActivityChart::canView());
        $this->assertTrue(CategoryStatistics::canView());
        $this->assertFalse(UsersByRoleChart::canView());
        Livewire::test(UsersByRoleChart::class)->assertForbidden();

        $this->actingAs(User::factory()->contributor()->create());
        $this->assertFalse(RecipeStatusChart::canView());
        Livewire::test(CategoryStatistics::class)->assertForbidden();

        $this->actingAs(User::factory()->admin()->create());
        app()->setLocale('el');
        Livewire::test(MostFavoritedRecipes::class)->assertSee('Δεν υπάρχουν αγαπημένα ακόμη');
        Livewire::test(CategoryStatistics::class)->assertSee('Δεν υπάρχουν κατηγορίες ακόμη');
        Livewire::test(UsersByRoleChart::class)->assertSee('Χρήστες ανά ρόλο');
        Livewire::test(RecipeStatusChart::class)->assertSee('Κατάσταση συνταγών');
    }

    public function test_dashboard_page_renders_with_all_stage_two_widgets(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get('/admin')->assertOk();
    }
}
