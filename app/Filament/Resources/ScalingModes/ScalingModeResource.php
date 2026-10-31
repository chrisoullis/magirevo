<?php

namespace App\Filament\Resources\ScalingModes;

use App\Filament\Resources\ScalingModes\Pages\CreateScalingMode;
use App\Filament\Resources\ScalingModes\Pages\EditScalingMode;
use App\Filament\Resources\ScalingModes\Pages\ListScalingModes;
use App\Filament\Resources\ScalingModes\Schemas\ScalingModeForm;
use App\Filament\Resources\ScalingModes\Tables\ScalingModesTable;
use App\Models\ScalingMode;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ScalingModeResource extends Resource
{
    protected static ?string $model = ScalingMode::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Recipe data';

    protected static ?int $navigationSort = 60;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['translations']);
    }

    public static function form(Schema $schema): Schema
    {
        return ScalingModeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ScalingModesTable::configure($table);
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
            'index' => ListScalingModes::route('/'),
            'create' => CreateScalingMode::route('/create'),
            'edit' => EditScalingMode::route('/{record}/edit'),
        ];
    }
}
