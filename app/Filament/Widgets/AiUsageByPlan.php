<?php

namespace App\Filament\Widgets;

use App\Support\AppPermissions;

/**
 * Reserved for the monetization phase. No payment system exists, so this only explains its absence.
 */
class AiUsageByPlan extends UnavailableMetricWidget
{
    protected static ?int $sort = 95;

    public static function canView(): bool
    {
        return static::staff() && auth()->user()->can(AppPermissions::QUOTAS_MANAGE);
    }

    protected function key(): string
    {
        return 'ai_plan';
    }

    protected function icon(): string
    {
        return 'heroicon-o-sparkles';
    }
}
