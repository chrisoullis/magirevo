<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ManagesQuotas;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PlanOverview extends StatsOverviewWidget
{
    use ManagesQuotas;

    protected static ?int $sort = 90;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    protected function getHeading(): ?string
    {
        return __('dashboard.plans.heading');
    }

    protected function getDescription(): ?string
    {
        return __('dashboard.plans.description');
    }

    protected function getStats(): array
    {
        $plans = $this->statistics()->usersByPlan(auth()->user()) ?? [];

        return collect($plans)->map(fn (int $users, string $code): Stat => Stat::make(__('dashboard.plans.users_on', ['plan' => ucfirst($code)]), $users)
            ->icon('heroicon-o-user-group')
            ->color($code === 'free' ? 'gray' : 'primary'))
            ->values()
            ->all();
    }
}
