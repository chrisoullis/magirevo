<?php

namespace App\Filament\Resources\RecipeRatings;

use App\Filament\Resources\RecipeRatings\Pages\ManageRecipeRatings;
use App\Models\Recipe;
use App\Models\RecipeRating;
use App\Services\RecipeRatingModerationService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RecipeRatingResource extends Resource
{
    protected static ?string $model = RecipeRating::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereIn('recipe_id', Recipe::query()->published()->select('id'))
            ->with(['recipe.translations', 'user:id,name', 'reports'])
            ->withCount(['reports as pending_reports_count' => fn (Builder $query) => $query->where('status', 'pending')]);
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
                    ->state(fn (RecipeRating $record): string => (string) ($record->recipe->displayTranslation('en')?->title ?? $record->recipe->id))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'recipe.translations',
                        fn (Builder $translations) => $translations->where('title', 'ilike', '%'.addcslashes($search, '%_\\').'%'),
                    )),
                TextColumn::make('user.name')->label('Member')->searchable(),
                TextColumn::make('rating')->badge()->sortable(),
                TextColumn::make('review_text')->label('Public review')->limit(90)->wrap()->placeholder('No written review'),
                TextColumn::make('review_status')->badge()->sortable(),
                TextColumn::make('pending_reports_count')->label('Open reports')->sortable(),
                TextColumn::make('pending_report_reasons')
                    ->label('Report details')
                    ->state(fn (RecipeRating $record): string => $record->reports
                        ->where('status', 'pending')
                        ->pluck('reason')
                        ->implode(' | '))
                    ->limit(100)
                    ->wrap(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('review_status')
                    ->options(['pending' => 'Pending', 'published' => 'Published', 'rejected' => 'Rejected']),
            ])
            ->recordActions([
                Action::make('moderateReview')
                    ->label('Moderate review')
                    ->visible(fn (RecipeRating $record): bool => filled($record->review_text) && $record->rating_status === 'active')
                    ->form([
                        Select::make('review_status')
                            ->options(['pending' => 'Pending', 'published' => 'Published', 'rejected' => 'Rejected'])
                            ->required(),
                    ])
                    ->action(fn (RecipeRating $record, array $data) => app(RecipeRatingModerationService::class)
                        ->moderateReview(auth()->user(), $record, $data['review_status'])),
                Action::make('removeRating')
                    ->label('Remove fraudulent rating')
                    ->color('danger')
                    ->visible(fn (RecipeRating $record): bool => $record->rating_status === 'active' && $record->pending_reports_count > 0)
                    ->form([
                        Textarea::make('reason')->required()->minLength(5)->maxLength(1000),
                    ])
                    ->action(fn (RecipeRating $record, array $data) => app(RecipeRatingModerationService::class)
                        ->removeRating(auth()->user(), $record, $data['reason'])),
                Action::make('dismissReports')
                    ->label('Dismiss reports')
                    ->color('gray')
                    ->visible(fn (RecipeRating $record): bool => $record->pending_reports_count > 0)
                    ->requiresConfirmation()
                    ->action(fn (RecipeRating $record) => app(RecipeRatingModerationService::class)
                        ->dismissPendingReports(auth()->user(), $record)),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageRecipeRatings::route('/'),
        ];
    }
}
