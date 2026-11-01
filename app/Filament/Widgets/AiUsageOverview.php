<?php

namespace App\Filament\Widgets;

class AiUsageOverview extends UnavailableMetricWidget
{
    protected static ?int $sort = 82;

    protected function key(): string
    {
        return 'ai';
    }

    protected function icon(): string
    {
        return 'heroicon-o-sparkles';
    }
}
