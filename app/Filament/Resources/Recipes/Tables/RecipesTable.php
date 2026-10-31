<?php

namespace App\Filament\Resources\Recipes\Tables;

use App\Models\Recipe;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RecipesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->state(fn (Recipe $record): ?string => $record->translate('en')?->title ?? $record->translate('el')?->title)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'translations',
                        fn (Builder $translation) => $translation->where('title', 'ilike', '%'.addcslashes($search, '%_\\').'%'),
                    )),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'published' => 'success',
                        'pending' => 'warning',
                        'archived' => 'gray',
                        default => 'info',
                    })
                    ->sortable(),
                TextColumn::make('categories')
                    ->state(fn (Recipe $record): array => $record->categories->map(fn ($category) => $category->translate('en')?->name ?? $category->code)->all())
                    ->badge(),
                TextColumn::make('difficulty')->sortable(),
                TextColumn::make('prep_minutes')->label('Prep')->suffix(' min')->sortable(),
                IconColumn::make('is_featured')->boolean()->label('Featured'),
                TextColumn::make('published_at')->date()->sortable()->placeholder('—'),
                TextColumn::make('updated_at')->since()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options([
                    'draft' => 'Draft',
                    'pending' => 'Pending review',
                    'published' => 'Published',
                    'archived' => 'Archived',
                ]),
                SelectFilter::make('categories')->relationship('categories', 'code')->multiple()->preload(),
                TernaryFilter::make('is_featured')->label('Featured'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
