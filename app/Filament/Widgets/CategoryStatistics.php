<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\AuthorizesDashboardWidget;
use App\Models\Category;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class CategoryStatistics extends TableWidget
{
    use AuthorizesDashboardWidget;

    protected static ?int $sort = 70;

    protected int|string|array $columnSpan = ['default' => 'full', 'xl' => 1];

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return static::staff();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('dashboard.categories.heading'))
            ->description(__('dashboard.categories.description'))
            ->query(fn () => $this->statistics()->categoryCounts(auth()->user())->with('translations'))
            ->paginated([10, 25])
            ->defaultPaginationPageOption(10)
            ->columns([
                TextColumn::make('name')
                    ->label(__('dashboard.columns.category'))
                    ->state(fn (Category $record): string => ($record->isRoot() ? '' : '— ').($record->getTranslation(app()->getLocale(), true)?->name ?? $record->code))
                    ->weight(fn (Category $record): string => $record->isRoot() ? 'bold' : 'normal'),
                TextColumn::make('dashboard_direct_count')
                    ->label(__('dashboard.columns.direct'))
                    ->numeric()
                    ->visibleFrom('sm'),
                TextColumn::make('dashboard_total_count')
                    ->label(__('dashboard.columns.total'))
                    ->numeric()
                    ->tooltip(__('dashboard.categories.total_help')),
            ])
            ->emptyStateHeading(__('dashboard.categories.empty'))
            ->emptyStateIcon('heroicon-o-tag');
    }
}
