<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\AuthorizesDashboardWidget;
use App\Services\DashboardStatisticsService;
use Filament\Widgets\ChartWidget;

class RecipeActivityChart extends ChartWidget
{
    use AuthorizesDashboardWidget;

    protected static ?int $sort = 50;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '280px';

    public ?string $filter = '30';

    public static function canView(): bool
    {
        return static::staff();
    }

    public function getHeading(): ?string
    {
        return __('dashboard.activity.heading');
    }

    public function getDescription(): ?string
    {
        return __('dashboard.activity.description');
    }

    protected function getFilters(): ?array
    {
        return collect(DashboardStatisticsService::ACTIVITY_RANGES)
            ->mapWithKeys(fn (int $days): array => [(string) $days => __('dashboard.activity.last_days', ['days' => $days])])
            ->all();
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $data = $this->statistics()->activity(auth()->user(), (int) $this->filter);

        return [
            'labels' => $data['labels'],
            'datasets' => [
                ['label' => __('dashboard.activity.created'), 'data' => $data['created'], 'borderColor' => '#f59e0b', 'backgroundColor' => 'rgba(245,158,11,0.15)', 'fill' => true, 'tension' => 0.3],
                ['label' => __('dashboard.activity.published'), 'data' => $data['published'], 'borderColor' => '#10b981', 'backgroundColor' => 'rgba(16,185,129,0.15)', 'fill' => true, 'tension' => 0.3],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return ['scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]]];
    }
}
