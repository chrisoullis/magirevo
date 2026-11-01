<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Recipes\RecipeResource;
use App\Filament\Widgets\Concerns\AuthorizesDashboardWidget;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class RecipeQualityMonitor extends StatsOverviewWidget
{
    use AuthorizesDashboardWidget;

    protected static ?int $sort = 30;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return static::staff();
    }

    protected function getHeading(): ?string
    {
        return __('dashboard.quality.heading');
    }

    protected function getDescription(): ?string
    {
        return __('dashboard.quality.description');
    }

    protected function getStats(): array
    {
        $issues = $this->statistics()->qualityIssues(auth()->user());
        $icons = [
            'translations' => 'heroicon-o-language',
            'cover' => 'heroicon-o-photo',
            'ingredients' => 'heroicon-o-beaker',
            'steps' => 'heroicon-o-list-bullet',
            'seo' => 'heroicon-o-magnifying-glass',
        ];

        return collect($issues)->map(fn (int $count, string $check): Stat => Stat::make(__("dashboard.quality.{$check}"), $count)
            ->description($count === 0 ? __('dashboard.quality.ok') : __('dashboard.quality.needs_attention'))
            ->icon($icons[$check])
            ->color($count === 0 ? 'success' : 'danger')
            ->url($count === 0 ? null : RecipeResource::getUrl('index')))
            ->values()
            ->all();
    }

    protected function getColumns(): int
    {
        return 5;
    }
}
