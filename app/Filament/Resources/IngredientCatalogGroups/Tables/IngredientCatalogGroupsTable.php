<?php

namespace App\Filament\Resources\IngredientCatalogGroups\Tables;

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

class IngredientCatalogGroupsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TranslatedColumn::make('en'),
                TranslatedColumn::make('el'),
                TextColumn::make('code')->searchable()->sortable(),
                IconColumn::make('is_active')->boolean()->label('Active')->sortable(),
                TextColumn::make('sort_order')->sortable(),
                TextColumn::make('ingredients_count')->counts('ingredients')->label('Ingredients'),
            ])
            ->defaultSort('sort_order')
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
