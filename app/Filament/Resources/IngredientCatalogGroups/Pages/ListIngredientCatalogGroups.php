<?php

namespace App\Filament\Resources\IngredientCatalogGroups\Pages;

use App\Filament\Resources\IngredientCatalogGroups\IngredientCatalogGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListIngredientCatalogGroups extends ListRecords
{
    protected static string $resource = IngredientCatalogGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
