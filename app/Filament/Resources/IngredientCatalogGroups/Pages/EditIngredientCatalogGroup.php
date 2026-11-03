<?php

namespace App\Filament\Resources\IngredientCatalogGroups\Pages;

use App\Filament\Resources\IngredientCatalogGroups\IngredientCatalogGroupResource;
use App\Filament\Support\DeletionGuard;
use App\Filament\Support\FillsTranslatedFormData;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditIngredientCatalogGroup extends EditRecord
{
    use FillsTranslatedFormData;

    protected static string $resource = IngredientCatalogGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->before(DeletionGuard::single()),
        ];
    }
}
