<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Recipes\RecipeResource;
use App\Filament\Widgets\ActiveSubscriptions;
use App\Filament\Widgets\AiUsageByPlan;
use App\Filament\Widgets\AiUsageOverview;
use App\Filament\Widgets\CategoryStatistics;
use App\Filament\Widgets\ContributorApplications;
use App\Filament\Widgets\Ga4TrafficOverview;
use App\Filament\Widgets\KpiOverview;
use App\Filament\Widgets\MonthlyRevenue;
use App\Filament\Widgets\MostFavoritedRecipes;
use App\Filament\Widgets\MostViewedRecipes;
use App\Filament\Widgets\PendingApprovals;
use App\Filament\Widgets\PlanOverview;
use App\Filament\Widgets\QuotaUtilization;
use App\Filament\Widgets\RecentApplicationErrors;
use App\Filament\Widgets\RecentRecipes;
use App\Filament\Widgets\RecipeActivityChart;
use App\Filament\Widgets\RecipeQualityMonitor;
use App\Filament\Widgets\RecipeStatusChart;
use App\Filament\Widgets\SubscriptionCancellations;
use App\Filament\Widgets\SystemHealth;
use App\Filament\Widgets\UsersByRoleChart;
use App\Models\Recipe;
use App\Support\AppPermissions;
use Filament\Actions\Action;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Contracts\Support\Htmlable;

class Dashboard extends BaseDashboard
{
    public function getTitle(): string|Htmlable
    {
        return __('dashboard.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('dashboard.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('dashboard.subheading');
    }

    public function getWidgets(): array
    {
        return [
            KpiOverview::class,
            PendingApprovals::class,
            ContributorApplications::class,
            RecipeQualityMonitor::class,
            RecipeActivityChart::class,
            RecipeStatusChart::class,
            UsersByRoleChart::class,
            RecentRecipes::class,
            CategoryStatistics::class,
            MostFavoritedRecipes::class,
            Ga4TrafficOverview::class,
            MostViewedRecipes::class,
            AiUsageOverview::class,
            SystemHealth::class,
            RecentApplicationErrors::class,
            PlanOverview::class,
            QuotaUtilization::class,
            ActiveSubscriptions::class,
            MonthlyRevenue::class,
            SubscriptionCancellations::class,
            AiUsageByPlan::class,
        ];
    }

    public function getColumns(): int|array
    {
        return ['default' => 1, 'xl' => 2];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addRecipe')
                ->label(__('dashboard.actions.add_recipe'))
                ->icon('heroicon-m-plus')
                ->visible(fn (): bool => auth()->user()?->can('create', Recipe::class) === true)
                ->url(fn (): string => RecipeResource::getUrl('create')),
            Action::make('reviewPending')
                ->label(__('dashboard.actions.review_pending'))
                ->icon('heroicon-m-clipboard-document-check')
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->can(AppPermissions::RECIPES_REVIEW) === true)
                ->url(fn (): string => RecipeResource::getUrl('index', ['filters' => ['status' => ['value' => 'pending']]])),
        ];
    }
}
