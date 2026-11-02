<?php

namespace App\Filament\Resources\IngredientSubstitutions\Pages;

use App\Filament\Resources\IngredientSubstitutions\IngredientSubstitutionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditIngredientSubstitution extends EditRecord
{
    protected static string $resource = IngredientSubstitutionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
