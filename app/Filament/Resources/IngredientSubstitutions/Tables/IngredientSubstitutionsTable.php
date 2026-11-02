<?php

namespace App\Filament\Resources\IngredientSubstitutions\Tables;

use App\Models\IngredientSubstitution;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class IngredientSubstitutionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('original_ingredient')
                    ->label('Original ingredient')
                    ->state(fn (IngredientSubstitution $record): string => (string) ($record->ingredient->translate('en')?->name ?? $record->ingredient->code))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'ingredient.translations',
                        fn (Builder $translations) => $translations->where('name', 'ilike', '%'.addcslashes($search, '%_\\').'%'),
                    )),
                TextColumn::make('replacement_ingredient')
                    ->label('Replacement')
                    ->state(fn (IngredientSubstitution $record): string => (string) ($record->replacementIngredient->translate('en')?->name ?? $record->replacementIngredient->code))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'replacementIngredient.translations',
                        fn (Builder $translations) => $translations->where('name', 'ilike', '%'.addcslashes($search, '%_\\').'%'),
                    )),
                TextColumn::make('recipe')
                    ->label('Applicability')
                    ->state(fn (IngredientSubstitution $record): string => $record->recipe?->translate('en')?->title ?? 'All recipes')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'recipe.translations',
                        fn (Builder $translations) => $translations->where('title', 'ilike', '%'.addcslashes($search, '%_\\').'%'),
                    )),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('en_explanation')
                    ->label('English explanation')
                    ->state(fn (IngredientSubstitution $record): ?string => $record->translate('en')?->explanation)
                    ->limit(100)
                    ->wrap(),
                TextColumn::make('el_explanation')
                    ->label('Greek explanation')
                    ->state(fn (IngredientSubstitution $record): ?string => $record->translate('el')?->explanation)
                    ->limit(100)
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending approval',
                        'approved' => 'Approved and active',
                        'inactive' => 'Inactive',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
