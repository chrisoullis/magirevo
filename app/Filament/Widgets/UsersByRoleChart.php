<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\AuthorizesDashboardWidget;
use App\Support\AppPermissions;
use Filament\Widgets\ChartWidget;

class UsersByRoleChart extends ChartWidget
{
    use AuthorizesDashboardWidget;

    protected static ?int $sort = 61;

    protected int|string|array $columnSpan = ['default' => 'full', 'xl' => 1];

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '260px';

    public static function canView(): bool
    {
        return static::staff() && auth()->user()->can(AppPermissions::USERS_MANAGE);
    }

    public function getHeading(): ?string
    {
        return __('dashboard.roles.heading');
    }

    public function getDescription(): ?string
    {
        return __('dashboard.roles.description');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $counts = $this->statistics()->usersByRole(auth()->user()) ?? [];

        return [
            'labels' => array_map(fn (string $role): string => __("dashboard.role.{$role}"), array_keys($counts)),
            'datasets' => [['label' => __('dashboard.roles.users'), 'data' => array_values($counts), 'backgroundColor' => '#f59e0b']],
        ];
    }

    protected function getOptions(): array
    {
        return ['plugins' => ['legend' => ['display' => false]], 'scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]]];
    }
}
