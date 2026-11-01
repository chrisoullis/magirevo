<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\AuthorizesDashboardWidget;
use Filament\Widgets\ChartWidget;

class RecipeStatusChart extends ChartWidget
{
    use AuthorizesDashboardWidget;

    protected static ?int $sort = 60;

    protected int|string|array $columnSpan = ['default' => 'full', 'xl' => 1];

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '260px';

    public static function canView(): bool
    {
        return static::staff();
    }

    public function getHeading(): ?string
    {
        return __('dashboard.status_chart.heading');
    }

    public function getDescription(): ?string
    {
        return __('dashboard.status_chart.description');
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $counts = $this->statistics()->statusDistribution(auth()->user());

        return [
            'labels' => array_map(fn (string $status): string => __("dashboard.status.{$status}"), array_keys($counts)),
            'datasets' => [['data' => array_values($counts), 'backgroundColor' => ['#60a5fa', '#f59e0b', '#10b981', '#9ca3af']]],
        ];
    }
}
