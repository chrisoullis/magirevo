<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Recipes\RecipeResource;
use App\Filament\Widgets\Concerns\AuthorizesDashboardWidget;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class KpiOverview extends StatsOverviewWidget
{
    use AuthorizesDashboardWidget;

    protected static ?int $sort = 10;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return static::staff();
    }

    protected function getStats(): array
    {
        $kpis = $this->statistics()->kpis(auth()->user());
        $recipes = fn (string $status): string => RecipeResource::getUrl('index', ['filters' => ['status' => ['value' => $status]]]);

        $stats = [
            Stat::make(__('dashboard.kpi.published'), $kpis['published'])
                ->description(__('dashboard.kpi.published_help'))
                ->icon('heroicon-o-globe-alt')
                ->color('success')
                ->url($recipes('published')),
            Stat::make(__('dashboard.kpi.pending'), $kpis['pending'])
                ->description(__('dashboard.kpi.pending_help'))
                ->icon('heroicon-o-clock')
                ->color('warning')
                ->url($recipes('pending')),
            Stat::make(__('dashboard.kpi.draft'), $kpis['draft'])
                ->description(__('dashboard.kpi.draft_help'))
                ->icon('heroicon-o-pencil-square')
                ->color('gray')
                ->url($recipes('draft')),
        ];

        if ($kpis['users'] !== null) {
            $stats[] = Stat::make(__('dashboard.kpi.users'), $kpis['users'])
                ->description(__('dashboard.kpi.users_help'))
                ->icon('heroicon-o-users')
                ->color('info');
        }

        return $stats;
    }
}
