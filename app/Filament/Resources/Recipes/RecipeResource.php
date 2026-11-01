<?php

namespace App\Filament\Resources\Recipes;

use App\Filament\Resources\Recipes\Pages\CreateRecipe;
use App\Filament\Resources\Recipes\Pages\EditRecipe;
use App\Filament\Resources\Recipes\Pages\ListRecipes;
use App\Filament\Resources\Recipes\RelationManagers\MediaRelationManager;
use App\Filament\Resources\Recipes\Schemas\RecipeForm;
use App\Filament\Resources\Recipes\Tables\RecipesTable;
use App\Models\Recipe;
use App\Support\AppPermissions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class RecipeResource extends Resource
{
    protected static ?string $model = Recipe::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->manageableBy(auth()->user())
            ->with(['translations', 'categories.translations', 'author', 'reviewer'])
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getNavigationBadge(): ?string
    {
        if (! auth()->user()?->can(AppPermissions::RECIPES_REVIEW)) {
            return null;
        }

        $pending = Recipe::query()->where('status', 'pending')->where('author_id', '<>', auth()->id())->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Recipes waiting for review';
    }

    public static function form(Schema $schema): Schema
    {
        return RecipeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RecipesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            MediaRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRecipes::route('/'),
            'create' => CreateRecipe::route('/create'),
            'edit' => EditRecipe::route('/{record}/edit'),
        ];
    }
}
