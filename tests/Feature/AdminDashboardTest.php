<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\ContributorApplications;
use App\Filament\Widgets\KpiOverview;
use App\Filament\Widgets\PendingApprovals;
use App\Filament\Widgets\RecentRecipes;
use App\Filament\Widgets\RecipeQualityMonitor;
use App\Models\ContributorRequest;
use App\Models\Recipe;
use App\Models\User;
use App\Services\DashboardStatisticsService;
use App\Services\RecipePublishingService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    private function recipe(array $attributes = [], string $title = 'Soup'): Recipe
    {
        $slug = str($title)->slug()->toString().'-'.fake()->unique()->numerify('####');
        $recipe = Recipe::factory()->make($attributes);
        $recipe->fill([
            'en' => ['title' => $title, 'slug' => $slug],
            'el' => ['title' => $title.' EL', 'slug' => $slug.'-el'],
        ])->save();

        return $recipe;
    }

    private function publishedRecipe(array $attributes = [], string $title = 'Pie'): Recipe
    {
        return $this->recipe([...['published_at' => now()->subDay()], ...$attributes, 'status' => 'published', 'visibility' => 'public'], $title);
    }

    private function kpis(User $user): array
    {
        Cache::flush();

        return app(DashboardStatisticsService::class)->kpis($user);
    }

    public function test_kpis_count_only_publicly_live_published_recipes(): void
    {
        $admin = User::factory()->admin()->create();
        $this->publishedRecipe();
        $this->publishedRecipe(['published_at' => now()->addDay()], 'Scheduled');
        $this->recipe(['status' => 'published', 'visibility' => 'private', 'published_at' => now()], 'Hidden');
        $this->recipe(['status' => 'pending']);
        $this->recipe(['status' => 'draft']);
        $this->recipe(['status' => 'draft']);

        $kpis = $this->kpis($admin);

        $this->assertSame(1, $kpis['published']);
        $this->assertSame(1, $kpis['pending']);
        $this->assertSame(2, $kpis['draft']);
        $this->assertSame(User::count(), $kpis['users']);
    }

    public function test_editor_figures_exclude_other_users_private_recipes_and_user_totals(): void
    {
        $editor = User::factory()->editor()->create();
        $other = User::factory()->contributor()->create();
        $this->recipe(['author_id' => $other->id, 'status' => 'draft']);
        $this->recipe(['author_id' => $editor->id, 'status' => 'draft']);
        $this->recipe(['author_id' => $other->id, 'status' => 'pending']);

        $kpis = $this->kpis($editor);

        $this->assertSame(1, $kpis['draft']);
        $this->assertSame(1, $kpis['pending']);
        $this->assertNull($kpis['users']);
    }

    public function test_only_admins_and_editors_can_open_the_dashboard(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get('/admin')->assertOk();
        $this->actingAs(User::factory()->editor()->create())->get('/admin')->assertOk();
        $this->actingAs(User::factory()->contributor()->create())->get('/admin')->assertForbidden();
        $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
    }

    public function test_widget_visibility_follows_role_permissions(): void
    {
        $this->actingAs(User::factory()->editor()->create());
        $this->assertTrue(KpiOverview::canView());
        $this->assertTrue(PendingApprovals::canView());
        $this->assertFalse(ContributorApplications::canView());

        $this->actingAs(User::factory()->admin()->create());
        $this->assertTrue(ContributorApplications::canView());

        $this->actingAs(User::factory()->contributor()->create());
        $this->assertFalse(KpiOverview::canView());
        $this->assertFalse(PendingApprovals::canView());
        $this->assertFalse(RecentRecipes::canView());
    }

    public function test_widgets_are_not_reachable_by_unauthorized_users_even_when_called_directly(): void
    {
        $this->actingAs(User::factory()->contributor()->create());
        Livewire::test(RecentRecipes::class)->assertForbidden();

        $this->actingAs(User::factory()->editor()->create());
        Livewire::test(ContributorApplications::class)->assertForbidden();
    }

    public function test_recent_recipes_never_list_private_recipes_of_other_users_for_editors(): void
    {
        $editor = User::factory()->editor()->create();
        $this->recipe(['status' => 'draft'], 'Secret Stew');
        $own = $this->recipe(['author_id' => $editor->id], 'My Draft');
        $public = $this->publishedRecipe();

        $this->actingAs($editor);

        Livewire::test(RecentRecipes::class)
            ->assertCanSeeTableRecords([$own, $public])
            ->assertDontSee('Secret Stew')
            ->assertCountTableRecords(2);
    }

    public function test_admin_with_private_moderation_access_sees_every_recipe(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $private = $this->recipe(['status' => 'draft'], 'Private Stew');

        Livewire::test(RecentRecipes::class)->assertCanSeeTableRecords([$private]);
    }

    public function test_pending_approvals_list_only_pending_recipes_and_allow_review_actions(): void
    {
        $editor = User::factory()->editor()->create();
        $pending = $this->recipe(['status' => 'pending'], 'Awaiting');
        $draft = $this->recipe(['status' => 'draft'], 'Not Submitted');
        $this->actingAs($editor);

        Livewire::test(PendingApprovals::class)
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$draft])
            ->assertTableActionVisible('approve', $pending)
            ->assertTableActionVisible('review', $pending);
    }

    public function test_pending_approval_actions_go_through_the_publishing_service(): void
    {
        $editor = User::factory()->editor()->create();
        $recipe = $this->recipe(['status' => 'pending'], 'Incomplete');
        $this->actingAs($editor);

        Livewire::test(PendingApprovals::class)->callTableAction('approve', $recipe);

        $this->assertSame('pending', $recipe->fresh()->status);
    }

    public function test_contributor_applications_list_pending_requests_only(): void
    {
        $admin = User::factory()->admin()->create();
        $applicant = User::factory()->create(['name' => 'Pending Pat']);
        ContributorRequest::create(['user_id' => $applicant->id]);
        $done = User::factory()->create(['name' => 'Decided Dee']);
        ContributorRequest::create(['user_id' => $done->id, 'status' => 'rejected']);
        $this->actingAs($admin);

        Livewire::test(ContributorApplications::class)
            ->assertSee('Pending Pat')
            ->assertDontSee('Decided Dee');
    }

    public function test_quality_monitor_counts_match_the_publication_rules(): void
    {
        $editor = User::factory()->editor()->create();
        $complete = Recipe::factory()->complete()->create(['status' => 'published', 'visibility' => 'public', 'published_at' => now()]);
        $incomplete = $this->publishedRecipe();
        $draft = $this->recipe(['status' => 'draft'], 'Ignored Draft');

        $issues = app(DashboardStatisticsService::class)->qualityIssues($editor);

        $this->assertSame(['translations' => 1, 'cover' => 1, 'ingredients' => 1, 'steps' => 1, 'seo' => 2], $issues);

        $service = app(RecipePublishingService::class);
        $this->assertSame([], $service->publicationProblems($complete));
        $this->assertNotEmpty($service->publicationProblems($incomplete));
        $this->assertNotEmpty($service->publicationProblems($draft));
    }

    public function test_quality_monitor_flags_a_missing_greek_translation(): void
    {
        $editor = User::factory()->editor()->create();
        $recipe = Recipe::factory()->complete()->create(['status' => 'published', 'visibility' => 'public', 'published_at' => now()]);
        $recipe->translations()->where('locale', 'el')->delete();

        $issues = app(DashboardStatisticsService::class)->qualityIssues($editor);

        $this->assertSame(1, $issues['translations']);
        $this->assertSame(0, $issues['cover']);
    }

    public function test_statistics_are_cached_briefly(): void
    {
        $admin = User::factory()->admin()->create();
        $this->assertSame(0, $this->kpis($admin)['draft']);

        $this->recipe(['status' => 'draft']);

        $this->assertSame(0, app(DashboardStatisticsService::class)->kpis($admin)['draft']);
        Cache::flush();
        $this->assertSame(1, app(DashboardStatisticsService::class)->kpis($admin)['draft']);
    }

    public function test_labels_and_empty_states_are_translated(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        app()->setLocale('en');
        Livewire::test(PendingApprovals::class)->assertSee('Nothing is waiting for review');
        Livewire::test(ContributorApplications::class)->assertSee('No pending applications');
        Livewire::test(RecentRecipes::class)->assertSee('No recipes yet');

        app()->setLocale('el');
        Livewire::test(PendingApprovals::class)->assertSee('Τίποτα δεν περιμένει έλεγχο');
        Livewire::test(ContributorApplications::class)->assertSee('Δεν υπάρχουν εκκρεμείς αιτήσεις');
        Livewire::test(RecentRecipes::class)->assertSee('Δεν υπάρχουν συνταγές ακόμη');
        Livewire::test(KpiOverview::class)->assertSee('Δημοσιευμένες συνταγές')->assertSee('Εγγεγραμμένοι χρήστες');
        Livewire::test(RecipeQualityMonitor::class)->assertSee('Λείπουν μεταφράσεις');
    }

    public function test_every_english_dashboard_string_has_a_greek_translation(): void
    {
        $this->assertSame(
            array_keys(Arr::dot(require lang_path('en/dashboard.php'))),
            array_keys(Arr::dot(require lang_path('el/dashboard.php'))),
        );
    }

    public function test_dashboard_page_layout_is_responsive_and_shows_quick_actions(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $page = new Dashboard;
        $this->assertSame(['default' => 1, 'xl' => 2], $page->getColumns());
        $this->assertContains(PendingApprovals::class, $page->getWidgets());

        Livewire::test(Dashboard::class)
            ->assertActionVisible('addRecipe')
            ->assertActionVisible('reviewPending');

        $this->actingAs(User::factory()->editor()->create());
        $this->get('/admin')->assertOk()->assertSee('Add recipe')->assertSee('Review pending recipes');
    }

    public function test_dashboard_renders_and_defers_expensive_widgets(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->publishedRecipe();
        $this->recipe(['status' => 'pending'], 'Queue Item');

        $this->get('/admin')->assertOk()->assertSee('Dashboard');
    }
}
