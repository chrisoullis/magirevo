<?php

namespace App\Filament\Widgets;

use App\Enums\RecipeVisibility;
use App\Filament\Resources\Recipes\RecipeResource;
use App\Filament\Widgets\Concerns\AuthorizesDashboardWidget;
use App\Models\Recipe;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class RecentRecipes extends TableWidget
{
    use AuthorizesDashboardWidget;

    protected static ?int $sort = 62;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return static::staff();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('dashboard.recent.heading'))
            ->description(__('dashboard.recent.description'))
            ->query(fn () => $this->statistics()->recipesFor(auth()->user())
                ->with(['translations', 'author'])
                ->orderByDesc('updated_at')
                ->limit(8))
            ->paginated(false)
            ->columns([
                TextColumn::make('title')
                    ->label(__('dashboard.columns.title'))
                    ->state(fn (Recipe $record): string => $record->displayTranslation(app()->getLocale())?->title ?? __('dashboard.untitled'))
                    ->wrap(),
                TextColumn::make('author.name')
                    ->label(__('dashboard.columns.author'))
                    ->placeholder('—')
                    ->visibleFrom('md'),
                TextColumn::make('status')
                    ->label(__('dashboard.columns.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("dashboard.status.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'published' => 'success',
                        'pending' => 'warning',
                        'archived' => 'gray',
                        default => 'info',
                    }),
                TextColumn::make('visibility')
                    ->label(__('dashboard.columns.visibility'))
                    ->badge()
                    ->formatStateUsing(fn (RecipeVisibility $state): string => __("dashboard.visibility.{$state->value}"))
                    ->color(fn (RecipeVisibility $state): string => match ($state) {
                        RecipeVisibility::Public => 'success',
                        RecipeVisibility::Shared => 'warning',
                        RecipeVisibility::Private => 'gray',
                    })
                    ->visibleFrom('md'),
                TextColumn::make('updated_at')
                    ->label(__('dashboard.columns.updated'))
                    ->since()
                    ->visibleFrom('sm'),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label(__('dashboard.actions.edit'))
                    ->icon('heroicon-m-pencil-square')
                    ->authorize('update')
                    ->url(fn (Recipe $record): string => RecipeResource::getUrl('edit', ['record' => $record])),
            ])
            ->emptyStateHeading(__('dashboard.recent.empty'))
            ->emptyStateIcon('heroicon-o-book-open');
    }
}
