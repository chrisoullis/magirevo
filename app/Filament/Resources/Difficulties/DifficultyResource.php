<?php

namespace App\Filament\Resources\Difficulties;

use App\Filament\Resources\Difficulties\Pages\CreateDifficulty;
use App\Filament\Resources\Difficulties\Pages\EditDifficulty;
use App\Filament\Resources\Difficulties\Pages\ListDifficulties;
use App\Filament\Resources\Difficulties\Schemas\DifficultyForm;
use App\Filament\Resources\Difficulties\Tables\DifficultiesTable;
use App\Models\Difficulty;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class DifficultyResource extends Resource
{
    protected static ?string $model = Difficulty::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Recipe data';

    protected static ?int $navigationSort = 30;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['translations']);
    }

    public static function form(Schema $schema): Schema
    {
        return DifficultyForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DifficultiesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDifficulties::route('/'),
            'create' => CreateDifficulty::route('/create'),
            'edit' => EditDifficulty::route('/{record}/edit'),
        ];
    }
}
