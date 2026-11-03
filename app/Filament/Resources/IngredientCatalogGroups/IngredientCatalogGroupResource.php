<?php

namespace App\Filament\Resources\IngredientCatalogGroups;

use App\Filament\Resources\IngredientCatalogGroups\Pages\CreateIngredientCatalogGroup;
use App\Filament\Resources\IngredientCatalogGroups\Pages\EditIngredientCatalogGroup;
use App\Filament\Resources\IngredientCatalogGroups\Pages\ListIngredientCatalogGroups;
use App\Filament\Resources\IngredientCatalogGroups\Schemas\IngredientCatalogGroupForm;
use App\Filament\Resources\IngredientCatalogGroups\Tables\IngredientCatalogGroupsTable;
use App\Models\IngredientCatalogGroup;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class IngredientCatalogGroupResource extends Resource
{
    protected static ?string $model = IngredientCatalogGroup::class;

    protected static ?string $modelLabel = 'Ingredient group';

    protected static ?string $pluralModelLabel = 'Ingredient groups';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Recipe data';

    protected static ?int $navigationSort = 41;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['translations']);
    }

    public static function form(Schema $schema): Schema
    {
        return IngredientCatalogGroupForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return IngredientCatalogGroupsTable::configure($table);
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
            'index' => ListIngredientCatalogGroups::route('/'),
            'create' => CreateIngredientCatalogGroup::route('/create'),
            'edit' => EditIngredientCatalogGroup::route('/{record}/edit'),
        ];
    }
}
