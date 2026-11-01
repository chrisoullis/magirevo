<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Recipes\RecipeResource;
use App\Filament\Widgets\Concerns\AuthorizesDashboardWidget;
use App\Models\Recipe;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class MostFavoritedRecipes extends TableWidget
{
    use AuthorizesDashboardWidget;

    protected static ?int $sort = 71;

    protected int|string|array $columnSpan = ['default' => 'full', 'xl' => 1];

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return static::staff();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('dashboard.favorites.heading'))
            ->description(__('dashboard.favorites.description'))
            ->query(fn () => $this->statistics()->mostFavorited(auth()->user())->with('translations')->limit(5))
            ->paginated(false)
            ->columns([
                TextColumn::make('title')
                    ->label(__('dashboard.columns.title'))
                    ->state(fn (Recipe $record): string => $record->displayTranslation(app()->getLocale())?->title ?? __('dashboard.untitled'))
                    ->wrap(),
                TextColumn::make('favorited_by_count')
                    ->label(__('dashboard.columns.favorites'))
                    ->icon('heroicon-m-heart')
                    ->numeric(),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label(__('dashboard.actions.edit'))
                    ->icon('heroicon-m-pencil-square')
                    ->authorize('update')
                    ->url(fn (Recipe $record): string => RecipeResource::getUrl('edit', ['record' => $record])),
            ])
            ->emptyStateHeading(__('dashboard.favorites.empty'))
            ->emptyStateIcon('heroicon-o-heart');
    }
}
