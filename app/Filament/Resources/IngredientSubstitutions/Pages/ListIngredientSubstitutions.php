<?php

namespace App\Filament\Resources\IngredientSubstitutions\Pages;

use App\Filament\Resources\IngredientSubstitutions\IngredientSubstitutionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListIngredientSubstitutions extends ListRecords
{
    protected static string $resource = IngredientSubstitutionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
