<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\ContributorRequests\ContributorRequestResource;
use App\Filament\Widgets\Concerns\AuthorizesDashboardWidget;
use App\Models\ContributorRequest;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Facades\Gate;

class ContributorApplications extends TableWidget
{
    use AuthorizesDashboardWidget;

    protected static ?int $sort = 21;

    protected int|string|array $columnSpan = ['default' => 'full', 'xl' => 1];

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return static::staff() && Gate::allows('viewAny', ContributorRequest::class);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('dashboard.applications.heading'))
            ->description(__('dashboard.applications.description'))
            ->query(fn () => $this->statistics()->pendingContributorRequests()
                ->with('user')
                ->orderBy('created_at')
                ->limit(5))
            ->paginated(false)
            ->columns([
                TextColumn::make('user.name')
                    ->label(__('dashboard.columns.applicant'))
                    ->description(fn (ContributorRequest $record): ?string => $record->user?->email),
                TextColumn::make('created_at')
                    ->label(__('dashboard.columns.submitted'))
                    ->since(),
            ])
            ->recordActions([
                Action::make('review')
                    ->label(__('dashboard.actions.review'))
                    ->icon('heroicon-m-eye')
                    ->url(ContributorRequestResource::getUrl('index')),
            ])
            ->emptyStateHeading(__('dashboard.applications.empty'))
            ->emptyStateIcon('heroicon-o-user-plus');
    }
}
