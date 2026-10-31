<?php

namespace App\Filament\Resources\Units\Tables;

use App\Filament\Resources\Units\Schemas\UnitForm;
use App\Filament\Support\DeletionGuard;
use App\Filament\Support\TranslatedColumn;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class UnitsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TranslatedColumn::make('en'),
                TranslatedColumn::make('el'),
                TranslatedColumn::make('en', 'abbreviation', 'Abbreviation'),
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('unit_type')->label('Type')->badge()->sortable(),
                IconColumn::make('allow_fraction')->boolean()->label('Fractions'),
                IconColumn::make('is_scalable')->boolean()->label('Scalable'),
                TextColumn::make('sort_order')->sortable(),
            ])
            ->defaultSort('sort_order')
            ->filters([
                SelectFilter::make('unit_type')->label('Type')->options(UnitForm::TYPES),
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
