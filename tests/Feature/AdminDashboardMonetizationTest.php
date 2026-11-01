<?php

namespace Tests\Feature;

use App\Filament\Widgets\ActiveSubscriptions;
use App\Filament\Widgets\AiUsageByPlan;
use App\Filament\Widgets\MonthlyRevenue;
use App\Filament\Widgets\PlanOverview;
use App\Filament\Widgets\QuotaUtilization;
use App\Filament\Widgets\SubscriptionCancellations;
use App\Models\Plan;
use App\Models\Recipe;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Services\DashboardStatisticsService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

class AdminDashboardMonetizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        Cache::flush();
    }

    private function privateRecipes(User $author, int $count, string $visibility = 'private'): void
    {
        Recipe::factory()->count($count)->create(['author_id' => $author->id, 'visibility' => $visibility]);
    }

    public function test_quota_utilization_aggregates_capped_accounts_per_plan_and_exempts_staff(): void
    {
        $admin = User::factory()->admin()->create();
        Plan::free()->update(['max_personal_recipes' => 10]);

        $full = User::factory()->contributor()->create();
        $near = User::factory()->contributor()->create();
        $light = User::factory()->contributor()->create();
        $editor = User::factory()->editor()->create();
        $this->privateRecipes($full, 10);
        $this->privateRecipes($near, 8);
        $this->privateRecipes($light, 1);
        $this->privateRecipes($editor, 5);
        $this->privateRecipes($light, 3, 'shared');

        $free = app(DashboardStatisticsService::class)->quotaUtilization($admin)['free'];

        $this->assertSame(3, $free['accounts']);
        $this->assertSame(1, $free['at_limit']);
        $this->assertSame(1, $free['near_limit']);
        $this->assertSame(22, $free['used']);
        $this->assertSame(30, $free['capacity']);
    }

    public function test_overrides_and_premium_plans_are_reflected(): void
    {
        $admin = User::factory()->admin()->create();
        $premium = Plan::create(['code' => 'premium', 'is_active' => true, 'max_personal_recipes' => 100]);
        $member = User::factory()->contributor()->create();
        UserEntitlement::where('user_id', $member->id)->update(['plan_id' => $premium->id]);
        $unlimited = User::factory()->contributor()->create();
        UserEntitlement::where('user_id', $unlimited->id)->update(['plan_id' => $premium->id, 'personal_recipe_limit_override' => 4]);
        $this->privateRecipes($unlimited, 4);

        $stats = app(DashboardStatisticsService::class);

        $this->assertSame(2, $stats->usersByPlan($admin)['premium']);
        $this->assertSame(1, $stats->quotaUtilization($admin)['premium']['at_limit']);
        $this->assertSame(104, $stats->quotaUtilization($admin)['premium']['capacity']);
    }

    public function test_plan_and_quota_figures_are_hidden_from_users_without_quota_permission(): void
    {
        $editor = User::factory()->editor()->create();
        $stats = app(DashboardStatisticsService::class);

        $this->assertNull($stats->usersByPlan($editor));
        $this->assertNull($stats->quotaUtilization($editor));

        $this->actingAs($editor);
        foreach ([PlanOverview::class, QuotaUtilization::class, ActiveSubscriptions::class, MonthlyRevenue::class, SubscriptionCancellations::class, AiUsageByPlan::class] as $widget) {
            $this->assertFalse($widget::canView());
            Livewire::test($widget)->assertForbidden();
        }
    }

    public function test_reserved_widgets_show_no_numbers_and_explain_why(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ActiveSubscriptions::class)->assertSee('Not configured')->assertSee('payment system');
        Livewire::test(MonthlyRevenue::class)->assertSee('Revenue is not available yet');
        Livewire::test(SubscriptionCancellations::class)->assertSee('Cancellations are not available yet');
        Livewire::test(AiUsageByPlan::class)->assertSee('cannot be split by plan');
    }

    public function test_plan_widgets_render_real_counts_and_greek_labels(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        User::factory()->count(2)->create();

        Livewire::test(PlanOverview::class)->assertSee('Free users');
        app()->setLocale('el');
        Livewire::test(PlanOverview::class)->assertSee('Πλάνα')->assertSee('Χρήστες Free');
        Livewire::test(QuotaUtilization::class)->assertSee('Όριο προσωπικών συνταγών');
        Livewire::test(ActiveSubscriptions::class)->assertSee('Ενεργές συνδρομές');
    }

    public function test_dashboard_renders_for_admin_with_monetization_widgets(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get('/admin')->assertOk();
    }
}
