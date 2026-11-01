<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Recipes\Actions\ReviewActions;
use App\Filament\Resources\Recipes\RecipeResource;
use App\Filament\Widgets\Concerns\AuthorizesDashboardWidget;
use App\Models\Recipe;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class PendingApprovals extends TableWidget
{
    use AuthorizesDashboardWidget;

    protected static ?int $sort = 20;

    protected int|string|array $columnSpan = ['default' => 'full', 'xl' => 1];

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return static::reviewsRecipes();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('dashboard.approvals.heading'))
            ->description(__('dashboard.approvals.description'))
            ->query(fn () => $this->statistics()->pendingRecipes(auth()->user())
                ->with(['translations', 'author'])
                ->orderBy('submitted_at')
                ->limit(5))
            ->paginated(false)
            ->columns([
                TextColumn::make('title')
                    ->label(__('dashboard.columns.title'))
                    ->state(fn (Recipe $record): string => $record->displayTranslation(app()->getLocale())?->title ?? __('dashboard.untitled'))
                    ->description(fn (Recipe $record): ?string => $record->author?->name)
                    ->wrap(),
                TextColumn::make('submitted_at')
                    ->label(__('dashboard.columns.submitted'))
                    ->since()
                    ->placeholder('—'),
            ])
            ->recordActions([
                Action::make('review')
                    ->label(__('dashboard.actions.review'))
                    ->icon('heroicon-m-eye')
                    ->authorize('update')
                    ->url(fn (Recipe $record): string => RecipeResource::getUrl('edit', ['record' => $record])),
                ReviewActions::approve()->iconButton()->icon('heroicon-m-check'),
                ReviewActions::reject()->iconButton()->icon('heroicon-m-x-mark'),
            ])
            ->headerActions([
                Action::make('all')
                    ->label(__('dashboard.actions.view_all'))
                    ->link()
                    ->url(RecipeResource::getUrl('index', ['filters' => ['status' => ['value' => 'pending']]])),
            ])
            ->emptyStateHeading(__('dashboard.approvals.empty'))
            ->emptyStateIcon('heroicon-o-check-badge');
    }
}
