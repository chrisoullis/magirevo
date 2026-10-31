<?php

namespace App\Filament\Resources\Ingredients\Tables;

use App\Filament\Support\DeletionGuard;
use App\Filament\Support\TranslatedColumn;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class IngredientsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TranslatedColumn::make('en'),
                TranslatedColumn::make('el'),
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('defaultUnit.code')->label('Default unit')->placeholder('—'),
                IconColumn::make('is_active')->boolean()->label('Active')->sortable(),
                TextColumn::make('recipe_ingredients_count')->counts('recipeIngredients')->label('Recipe lines'),
            ])
            ->defaultSort('code')
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->before(DeletionGuard::single()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->before(DeletionGuard::bulk()),
                ]),
            ]);
    }
}
