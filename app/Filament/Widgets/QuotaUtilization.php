<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ManagesQuotas;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class QuotaUtilization extends StatsOverviewWidget
{
    use ManagesQuotas;

    protected static ?int $sort = 91;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    protected function getHeading(): ?string
    {
        return __('dashboard.quota.heading');
    }

    protected function getDescription(): ?string
    {
        return __('dashboard.quota.description');
    }

    protected function getStats(): array
    {
        $plans = $this->statistics()->quotaUtilization(auth()->user()) ?? [];

        if ($plans === []) {
            return [Stat::make(__('dashboard.quota.heading'), '—')->description(__('dashboard.quota.empty'))->icon('heroicon-o-chart-pie')];
        }

        return collect($plans)->map(function (array $plan, string $code): Stat {
            $percent = $plan['capacity'] > 0 ? (int) round($plan['used'] / $plan['capacity'] * 100) : 0;

            return Stat::make(__('dashboard.quota.plan_usage', ['plan' => ucfirst($code)]), "{$percent}%")
                ->description(__('dashboard.quota.breakdown', ['used' => $plan['used'], 'capacity' => $plan['capacity'], 'at' => $plan['at_limit'], 'near' => $plan['near_limit']]))
                ->icon('heroicon-o-chart-pie')
                ->color($plan['at_limit'] > 0 ? 'danger' : ($plan['near_limit'] > 0 ? 'warning' : 'success'));
        })->values()->all();
    }
}
