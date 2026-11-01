<?php

namespace App\Filament\Widgets;

use App\Support\AppPermissions;

/**
 * Reserved for the monetization phase. No payment system exists, so this only explains its absence.
 */
class ActiveSubscriptions extends UnavailableMetricWidget
{
    protected static ?int $sort = 92;

    public static function canView(): bool
    {
        return static::staff() && auth()->user()->can(AppPermissions::QUOTAS_MANAGE);
    }

    protected function key(): string
    {
        return 'subscriptions';
    }

    protected function icon(): string
    {
        return 'heroicon-o-credit-card';
    }
}
