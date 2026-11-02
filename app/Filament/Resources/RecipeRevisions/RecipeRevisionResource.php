<?php

namespace App\Filament\Resources\RecipeRevisions;

use App\Enums\RecipeRevisionStatus;
use App\Filament\Resources\RecipeRevisions\Pages\EditRecipeRevision;
use App\Filament\Resources\RecipeRevisions\Pages\ListRecipeRevisions;
use App\Filament\Resources\RecipeRevisions\Pages\ViewRecipeRevision;
use App\Filament\Resources\RecipeRevisions\Schemas\RecipeRevisionForm;
use App\Models\RecipeRevision;
use App\Support\AppPermissions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Review surface for recipe revisions. Content is never edited here for published recipes: drafts are edited as
 * payloads, reviewed, then applied by RecipeRevisionService.
 */
class RecipeRevisionResource extends Resource
{
    protected static ?string $model = RecipeRevision::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|\UnitEnum|null $navigationGroup = 'Recipe data';

    protected static ?int $navigationSort = 12;

    protected static ?string $modelLabel = 'recipe revision';

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $query = parent::getEloquentQuery()->with(['recipe.translations', 'creator', 'reviewer']);

        if ($user === null || ! $user->hasVerifiedEmail()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('recipe', function (Builder $recipes) use ($user): void {
            $recipes->where(function (Builder $accessible) use ($user): void {
                $accessible->where('author_id', $user->getKey());

                if ($user->can(AppPermissions::REVISIONS_VIEW_HISTORY)) {
                    $accessible->orWhere('visibility', 'public')->orWhere('status', 'pending');

                    if ($user->can(AppPermissions::RECIPES_MODERATE_PRIVATE)) {
                        $accessible->orWhereRaw('1 = 1');
                    }
                }
            });
        });
    }

    public static function canCreate(): bool
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
        return RecipeRevisionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('recipe')
                    ->label('Recipe')
                    ->state(fn (RecipeRevision $record): string => (string) ($record->recipe?->displayTranslation(app()->getLocale())?->title ?? 'Unavailable'))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas('recipe.translations', fn (Builder $translations): Builder => $translations->where('title', 'ilike', '%'.addcslashes($search, '%_\\').'%'))),
                TextColumn::make('revision_number')->label('#')->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('creator.name')->label('Created by')->placeholder('—'),
                TextColumn::make('submitted_at')->dateTime()->placeholder('—')->sortable(),
                TextColumn::make('reviewer.name')->label('Reviewed by')->placeholder('—'),
                TextColumn::make('published_at')->dateTime()->placeholder('—')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(RecipeRevisionStatus::cases())->mapWithKeys(fn (RecipeRevisionStatus $status): array => [$status->value => str($status->value)->replace('_', ' ')->title()->toString()])->all()),
            ])
            ->recordUrl(fn (RecipeRevision $record): string => static::getUrl('view', ['record' => $record]));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRecipeRevisions::route('/'),
            'view' => ViewRecipeRevision::route('/{record}'),
            'edit' => EditRecipeRevision::route('/{record}/edit'),
        ];
    }
}
