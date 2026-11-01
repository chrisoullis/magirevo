<?php

namespace Tests\Feature;

use App\Filament\Widgets\AiUsageOverview;
use App\Filament\Widgets\Ga4TrafficOverview;
use App\Filament\Widgets\MostViewedRecipes;
use App\Filament\Widgets\RecentApplicationErrors;
use App\Filament\Widgets\SystemHealth;
use App\Models\User;
use App\Services\SystemMonitoringService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class AdminDashboardMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        Cache::flush();
    }

    private function failedJob(string $failedAt): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => fake()->uuid(), 'connection' => 'database', 'queue' => 'default',
            'payload' => '{}', 'exception' => 'boom', 'failed_at' => $failedAt,
        ]);
    }

    public function test_failed_jobs_are_counted_from_the_failed_jobs_table(): void
    {
        $this->failedJob(now()->toDateTimeString());
        $this->failedJob(now()->subDays(3)->toDateTimeString());

        $this->assertSame(['total' => 2, 'last_day' => 1], app(SystemMonitoringService::class)->failedJobs());
    }

    public function test_log_summary_counts_recent_errors_and_warnings_only(): void
    {
        $file = storage_path('logs/laravel-test-monitor.log');
        $now = now()->format('Y-m-d H:i:s');
        $old = now()->subDays(3)->format('Y-m-d H:i:s');
        file_put_contents($file, implode("\n", [
            "[{$now}] production.ERROR: Payment exploded {\"exception\":\"x\"}",
            '#0 stack frame line',
            "[{$now}] production.WARNING: Slow query",
            "[{$now}] production.INFO: Fine",
            "[{$old}] production.ERROR: Ancient failure",
        ])."\n");
        touch($file, time() + 100);

        try {
            $summary = app(SystemMonitoringService::class)->logSummary();
        } finally {
            unlink($file);
        }

        $this->assertSame(1, $summary['errors']);
        $this->assertSame(1, $summary['warnings']);
        $this->assertStringContainsString('Payment exploded', $summary['recent'][0]['message']);
    }

    public function test_storage_usage_reports_real_file_sizes(): void
    {
        $dir = sys_get_temp_dir().'/dash-storage-'.uniqid();
        mkdir($dir);
        file_put_contents("{$dir}/a.bin", str_repeat('x', 1500));
        config(['dashboard.storage_paths' => ['public' => $dir, 'private' => $dir.'-missing']]);

        $usage = app(SystemMonitoringService::class)->storageUsage();
        unlink("{$dir}/a.bin");
        rmdir($dir);

        $this->assertSame(1500, $usage['used']);
        $this->assertSame(0, $usage['paths']['private']);
    }

    public function test_unconfigured_sources_show_an_empty_state_and_no_numbers(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Ga4TrafficOverview::class)->assertSee('Not configured')->assertSee('GA4_PROPERTY_ID');
        Livewire::test(MostViewedRecipes::class)->assertSee('not tracked yet');
        Livewire::test(AiUsageOverview::class)->assertSee('not recorded yet');
    }

    public function test_ga4_with_a_property_but_no_connector_still_does_not_fabricate_data(): void
    {
        config(['dashboard.ga4.property_id' => '123456']);
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Ga4TrafficOverview::class)->assertSee('no analytics connector');
    }

    public function test_unavailable_states_are_translated_to_greek(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        app()->setLocale('el');

        Livewire::test(AiUsageOverview::class)->assertSee('Δεν έχει ρυθμιστεί')->assertSee('δεν καταγράφονται ακόμη');
        Livewire::test(SystemHealth::class)->assertSee('Αποτυχημένες εργασίες ουράς');
        Livewire::test(RecentApplicationErrors::class)->assertSee('Πρόσφατα σφάλματα εφαρμογής');
    }

    public function test_system_widgets_show_failed_jobs_to_admins(): void
    {
        $this->failedJob(now()->toDateTimeString());
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(SystemHealth::class)->assertSee('Failed queue jobs')->assertSee('1 in the last 24 hours');
    }

    public function test_technical_widgets_are_admin_only_even_when_called_directly(): void
    {
        $this->actingAs(User::factory()->editor()->create());

        foreach ([SystemHealth::class, RecentApplicationErrors::class, Ga4TrafficOverview::class, MostViewedRecipes::class, AiUsageOverview::class] as $widget) {
            $this->assertFalse($widget::canView());
            Livewire::test($widget)->assertForbidden();
        }

        $this->actingAs(User::factory()->contributor()->create());
        $this->assertFalse(SystemHealth::canView());
    }

    public function test_dashboard_renders_with_monitoring_widgets(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get('/admin')->assertOk();
        $this->actingAs(User::factory()->editor()->create())->get('/admin')->assertOk();
    }
}
