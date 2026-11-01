<?php

namespace App\Filament\Widgets\Concerns;

use App\Services\SystemMonitoringService;
use App\Support\AppPermissions;

/**
 * Technical data (logs, queue, storage) is for administrators only, not editors.
 */
trait RequiresAdministrator
{
    use AuthorizesDashboardWidget;

    public static function canView(): bool
    {
        return static::staff() && auth()->user()->hasRole(AppPermissions::ADMIN);
    }

    protected function system(): SystemMonitoringService
    {
        return app(SystemMonitoringService::class);
    }
}
