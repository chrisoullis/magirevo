<?php

namespace App\Filament\Resources\IngredientSubstitutions;

use App\Filament\Resources\IngredientSubstitutions\Pages\CreateIngredientSubstitution;
use App\Filament\Resources\IngredientSubstitutions\Pages\EditIngredientSubstitution;
use App\Filament\Resources\IngredientSubstitutions\Pages\ListIngredientSubstitutions;
use App\Filament\Resources\IngredientSubstitutions\Schemas\IngredientSubstitutionForm;
use App\Filament\Resources\IngredientSubstitutions\Tables\IngredientSubstitutionsTable;
use App\Models\IngredientSubstitution;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class IngredientSubstitutionResource extends Resource
{
    protected static ?string $model = IngredientSubstitution::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Recipe data';

    protected static ?int $navigationSort = 45;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'translations',
            'ingredient.translations',
            'replacementIngredient.translations',
            'recipe.translations',
        ]);
    }

    public static function form(Schema $schema): Schema
    {
        return IngredientSubstitutionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return IngredientSubstitutionsTable::configure($table);
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
            'index' => ListIngredientSubstitutions::route('/'),
            'create' => CreateIngredientSubstitution::route('/create'),
            'edit' => EditIngredientSubstitution::route('/{record}/edit'),
        ];
    }
}
