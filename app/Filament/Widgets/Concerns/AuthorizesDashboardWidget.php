<?php

namespace App\Filament\Widgets\Concerns;

use App\Services\DashboardStatisticsService;
use App\Support\AppPermissions;
use Filament\Widgets\Widget;

/**
 * Hiding a widget is not authorization: Livewire widgets can be called directly, so every request re-checks
 * canView(). Queries behind the widgets are additionally scoped by DashboardStatisticsService and policies.
 */
trait AuthorizesDashboardWidget
{
    public function bootAuthorizesDashboardWidget(): void
    {
        abort_unless(static::canView(), 403);
    }

    protected static function staff(): bool
    {
        return auth()->user()?->isStaff() === true;
    }

    protected static function reviewsRecipes(): bool
    {
        return static::staff() && auth()->user()->can(AppPermissions::RECIPES_REVIEW);
    }

    protected function statistics(): DashboardStatisticsService
    {
        return app(DashboardStatisticsService::class);
    }
}
