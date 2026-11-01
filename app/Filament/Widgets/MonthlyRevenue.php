<?php

namespace App\Filament\Widgets;

use App\Support\AppPermissions;

/**
 * Reserved for the monetization phase. No payment system exists, so this only explains its absence.
 */
class MonthlyRevenue extends UnavailableMetricWidget
{
    protected static ?int $sort = 93;

    public static function canView(): bool
    {
        return static::staff() && auth()->user()->can(AppPermissions::QUOTAS_MANAGE);
    }

    protected function key(): string
    {
        return 'revenue';
    }

    protected function icon(): string
    {
        return 'heroicon-o-banknotes';
    }
}
