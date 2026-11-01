<?php

namespace App\Filament\Widgets\Concerns;

use App\Support\AppPermissions;

trait ManagesQuotas
{
    use AuthorizesDashboardWidget;

    public static function canView(): bool
    {
        return static::staff() && auth()->user()->can(AppPermissions::QUOTAS_MANAGE);
    }
}
