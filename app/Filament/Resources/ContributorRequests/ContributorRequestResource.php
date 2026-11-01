<?php

namespace App\Filament\Resources\ContributorRequests;

use App\Enums\ContributorRequestStatus;
use App\Filament\Resources\ContributorRequests\Pages\ListContributorRequests;
use App\Models\ContributorRequest;
use App\Services\ContributorRequestService;
use App\Support\AppPermissions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class ContributorRequestResource extends Resource
{
    protected static ?string $model = ContributorRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    protected static string|UnitEnum|null $navigationGroup = 'Community';

    protected static ?string $modelLabel = 'contributor application';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user.roles', 'reviewer']);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = ContributorRequest::query()->pending()->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')->label('Applicant')->searchable()->description(fn (ContributorRequest $record): string => $record->user->email),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (ContributorRequestStatus $state): string => ucfirst($state->value))
                    ->color(fn (ContributorRequestStatus $state): string => match ($state) {
                        ContributorRequestStatus::Approved => 'success',
                        ContributorRequestStatus::Pending => 'warning',
                        ContributorRequestStatus::Rejected => 'danger',
                        ContributorRequestStatus::Cancelled => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('message')->limit(80)->tooltip(fn (ContributorRequest $record): ?string => $record->message)->placeholder('—'),
                TextColumn::make('created_at')->label('Submitted')->dateTime()->sortable(),
                TextColumn::make('reviewer.name')->label('Reviewed by')->placeholder('—'),
                TextColumn::make('reviewed_at')->dateTime()->placeholder('—')->sortable(),
                TextColumn::make('rejection_reason')->limit(60)->placeholder('—')->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(ContributorRequestStatus::cases())->mapWithKeys(fn (ContributorRequestStatus $status): array => [$status->value => ucfirst($status->value)])->all())
                    ->default(ContributorRequestStatus::Pending->value),
            ])
            ->recordActions([
                Action::make('approve')
                    ->color('success')
                    ->requiresConfirmation()
                    ->authorize('approve')
                    ->visible(fn (ContributorRequest $record): bool => $record->isPending())
                    ->action(fn (ContributorRequest $record) => self::run(fn () => app(ContributorRequestService::class)->approve(auth()->user(), $record), 'Application approved')),
                Action::make('reject')
                    ->color('danger')
                    ->authorize('reject')
                    ->visible(fn (ContributorRequest $record): bool => $record->isPending())
                    ->schema([Textarea::make('rejection_reason')->required()->maxLength(1000)])
                    ->action(fn (ContributorRequest $record, array $data) => self::run(fn () => app(ContributorRequestService::class)->reject(auth()->user(), $record, $data['rejection_reason']), 'Application rejected')),
                Action::make('revoke')
                    ->label('Revoke access')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('The user keeps their existing recipes but can no longer create new ones.')
                    ->authorize('revoke')
                    ->visible(fn (ContributorRequest $record): bool => $record->status === ContributorRequestStatus::Approved
                        && $record->user->hasRole(AppPermissions::CONTRIBUTOR))
                    ->action(fn (ContributorRequest $record) => self::run(fn () => app(ContributorRequestService::class)->revokeContributor(auth()->user(), $record->user), 'Contributor access revoked')),
            ]);
    }

    private static function run(callable $operation, string $success): void
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title(collect($exception->errors())->flatten()->implode(' '))->send();

            return;
        }

        Notification::make()->success()->title($success)->send();
    }

    public static function getPages(): array
    {
        return ['index' => ListContributorRequests::route('/')];
    }
}
