<?php

namespace App\Filament\Widgets;

class Ga4TrafficOverview extends UnavailableMetricWidget
{
    protected static ?int $sort = 80;

    protected function key(): string
    {
        return 'ga4';
    }

    protected function icon(): string
    {
        return 'heroicon-o-chart-bar';
    }

    public function reason(): string
    {
        // No GA4 client is installed, so even a configured property cannot be queried yet.
        return __($this->system()->ga4Configured() ? 'dashboard.ga4.connector_missing' : 'dashboard.ga4.unavailable');
    }
}
