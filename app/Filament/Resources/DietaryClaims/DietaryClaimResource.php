<?php

namespace App\Filament\Resources\DietaryClaims;

use App\Enums\DietaryClaimStatus;
use App\Enums\DietaryVerificationMethod;
use App\Filament\Resources\DietaryClaims\Pages\ManageDietaryClaims;
use App\Models\DietaryClaim;
use App\Models\DietaryClaimAudit;
use App\Services\DietaryClaimService;
use App\Services\IngredientDietaryResolver;
use App\Support\AppPermissions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DietaryClaimResource extends Resource
{
    protected static ?string $model = DietaryClaim::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Recipe data';

    protected static ?int $navigationSort = 48;

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $query = parent::getEloquentQuery()->with(['recipe.translations', 'tag.translations', 'reviewer', 'audits.actor']);

        if ($user === null || ! $user->hasVerifiedEmail()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('recipe', function (Builder $recipes) use ($user): void {
            $recipes->where(function (Builder $accessible) use ($user): void {
                $accessible
                    ->where(fn (Builder $public): Builder => $public
                        ->where('status', 'published')
                        ->where('visibility', 'public'))
                    ->orWhere('author_id', $user->getKey());

                if ($user->can(AppPermissions::RECIPES_VIEW_SHARED)) {
                    $accessible->orWhere(fn (Builder $shared): Builder => $shared
                        ->where('visibility', 'shared')
                        ->whereHas('shares', fn (Builder $shares): Builder => $shares->active()->where('user_id', $user->getKey())));
                }

                if ($user->can('View:Recipe')) {
                    $accessible->orWhere('status', 'pending');

                    if ($user->can(AppPermissions::RECIPES_MODERATE_PRIVATE)) {
                        $accessible->orWhere(fn (Builder $private): Builder => $private
                            ->where('visibility', '!=', 'public')
                            ->where('status', '!=', 'pending'));
                    }
                }
            });
        });
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('recipe')
                    ->label('Recipe')
                    ->state(fn (DietaryClaim $record): string => (string) ($record->recipe?->displayTranslation(app()->getLocale())?->title ?? 'Unavailable'))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas('recipe.translations', fn (Builder $translations): Builder => $translations->where('title', 'ilike', '%'.addcslashes($search, '%_\\').'%'))),
                TextColumn::make('tag')
                    ->label('Classification')
                    ->state(fn (DietaryClaim $record): string => (string) ($record->tag?->translate(app()->getLocale())?->name ?? $record->tag?->code)),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('revision_hash')->label('Recipe revision')->limit(12)->tooltip(fn (DietaryClaim $record): string => $record->revision_hash),
                TextColumn::make('rule_version')->label('Rule version')->sortable(),
                TextColumn::make('reviewer.name')->label('Reviewed by')->placeholder('—'),
                TextColumn::make('reviewed_at')->dateTime()->placeholder('—')->sortable(),
            ])
            ->recordActions([
                ViewAction::make()
                    ->schema([
                        TextEntry::make('recipe')
                            ->label('Recipe')
                            ->state(fn (DietaryClaim $record): string => (string) ($record->recipe?->displayTranslation(app()->getLocale())?->title ?? 'Unavailable')),
                        TextEntry::make('tag')
                            ->label('Classification')
                            ->state(fn (DietaryClaim $record): string => (string) ($record->tag?->translate(app()->getLocale())?->name ?? $record->tag?->code)),
                        TextEntry::make('status'),
                        TextEntry::make('verification_method'),
                        TextEntry::make('revision_hash')->label('Recipe revision'),
                        TextEntry::make('rule_version')->label('Rule version'),
                        TextEntry::make('evidence')
                            ->state(fn (DietaryClaim $record): string => implode("\n", $record->evidence ?? []) ?: 'No evidence recorded.')
                            ->columnSpanFull(),
                        TextEntry::make('ingredient_evidence')
                            ->label('Ingredient supporting evidence')
                            ->state(fn (DietaryClaim $record): string => implode("\n", app(IngredientDietaryResolver::class)->evidence($record->contentSubject(), $record->tag)) ?: 'No ingredient evidence recorded.')
                            ->columnSpanFull(),
                        TextEntry::make('ingredient_review')
                            ->label('Ingredient review warnings')
                            ->state(fn (DietaryClaim $record): string => implode("\n", app(IngredientDietaryResolver::class)->warnings($record->contentSubject(), $record->tag)) ?: 'No unresolved ingredient warnings.')
                            ->columnSpanFull(),
                        TextEntry::make('audit_history')
                            ->label('Claims audit history')
                            ->state(fn (DietaryClaim $record): string => $record->audits
                                ->map(fn (DietaryClaimAudit $audit): string => implode(' | ', array_filter([
                                    $audit->created_at?->toDateTimeString(),
                                    $audit->action->value,
                                    $audit->actor?->name,
                                    $audit->notes,
                                    "revision {$audit->revision_hash}",
                                    "rules {$audit->rule_version}",
                                ])))
                                ->implode("\n"))
                            ->columnSpanFull(),
                    ]),
                Action::make('approve')
                    ->visible(fn (DietaryClaim $record): bool => $record->status === DietaryClaimStatus::Suggested
                        && auth()->user()?->can('approve', $record))
                    ->schema([
                        Select::make('verification_method')
                            ->options(collect(DietaryVerificationMethod::cases())->mapWithKeys(fn (DietaryVerificationMethod $method): array => [$method->value => str($method->value)->replace('_', ' ')->title()->toString()])->all())
                            ->default(DietaryVerificationMethod::EditorialIngredientReview->value)
                            ->required(),
                        Textarea::make('evidence')
                            ->helperText('Enter one supporting reference per line. Administrative approval is not professional certification.')
                            ->rows(4),
                        Textarea::make('notes')->rows(3),
                    ])
                    ->action(function (DietaryClaim $record, array $data, DietaryClaimService $service): void {
                        $service->approve(
                            auth()->user(),
                            $record,
                            DietaryVerificationMethod::from($data['verification_method']),
                            preg_split('/\R/u', (string) ($data['evidence'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [],
                            $data['notes'] ?? null,
                        );
                    }),
                Action::make('reject')
                    ->visible(fn (DietaryClaim $record): bool => $record->status === DietaryClaimStatus::Suggested
                        && auth()->user()?->can('reject', $record))
                    ->schema([Textarea::make('notes')->required()->minLength(5)->maxLength(1000)])
                    ->action(fn (DietaryClaim $record, array $data, DietaryClaimService $service) => $service->reject(auth()->user(), $record, $data['notes'])),
                Action::make('suspend')
                    ->color('danger')
                    ->visible(fn (DietaryClaim $record): bool => $record->status === DietaryClaimStatus::Approved
                        && auth()->user()?->can('suspend', $record))
                    ->schema([Textarea::make('reason')->required()->minLength(5)->maxLength(1000)])
                    ->action(fn (DietaryClaim $record, array $data, DietaryClaimService $service) => $service->suspend(auth()->user(), $record, $data['reason'])),
                Action::make('flag')
                    ->color('gray')
                    ->visible(fn (DietaryClaim $record): bool => auth()->user()?->can('flag', $record))
                    ->schema([Textarea::make('notes')->required()->minLength(5)->maxLength(1000)])
                    ->action(fn (DietaryClaim $record, array $data, DietaryClaimService $service) => $service->flag(auth()->user(), $record, $data['notes'])),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageDietaryClaims::route('/'),
        ];
    }
}
