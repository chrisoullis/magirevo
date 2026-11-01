<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\RequiresAdministrator;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

class SystemHealth extends StatsOverviewWidget
{
    use RequiresAdministrator;

    protected static ?int $sort = 83;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    protected function getHeading(): ?string
    {
        return __('dashboard.system.heading');
    }

    protected function getDescription(): ?string
    {
        return __('dashboard.system.description');
    }

    protected function getStats(): array
    {
        $failed = $this->system()->failedJobs();
        $log = $this->system()->logSummary();
        $storage = $this->system()->storageUsage();
        $na = __('dashboard.system.not_available');

        return [
            Stat::make(__('dashboard.system.failed_jobs'), $failed['total'] ?? $na)
                ->description($failed === null ? __('dashboard.system.no_queue_table') : __('dashboard.system.last_24h', ['count' => $failed['last_day']]))
                ->icon('heroicon-o-exclamation-triangle')
                ->color(($failed['total'] ?? 0) > 0 ? 'danger' : 'success'),
            Stat::make(__('dashboard.system.errors'), $log['errors'] ?? $na)
                ->description($log === null ? __('dashboard.errors.no_log') : __('dashboard.system.last_24h_window'))
                ->icon('heroicon-o-bug-ant')
                ->color(($log['errors'] ?? 0) > 0 ? 'danger' : 'success'),
            Stat::make(__('dashboard.system.warnings'), $log['warnings'] ?? $na)
                ->description($log === null ? __('dashboard.errors.no_log') : __('dashboard.system.last_24h_window'))
                ->icon('heroicon-o-exclamation-circle')
                ->color(($log['warnings'] ?? 0) > 0 ? 'warning' : 'success'),
            Stat::make(__('dashboard.system.storage'), Number::fileSize($storage['used']))
                ->description($storage['free'] === null ? '' : __('dashboard.system.free', ['size' => Number::fileSize($storage['free'])]))
                ->icon('heroicon-o-server-stack')
                ->color('info'),
        ];
    }

    protected function getColumns(): int
    {
        return 4;
    }
}
