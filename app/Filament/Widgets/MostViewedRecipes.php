<?php

namespace App\Filament\Widgets;

class MostViewedRecipes extends UnavailableMetricWidget
{
    protected static ?int $sort = 81;

    protected function key(): string
    {
        return 'views';
    }

    protected function icon(): string
    {
        return 'heroicon-o-eye';
    }
}
