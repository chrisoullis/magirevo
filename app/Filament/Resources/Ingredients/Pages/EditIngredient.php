<?php

namespace App\Filament\Resources\Ingredients\Pages;

use App\Filament\Resources\Ingredients\IngredientResource;
use App\Filament\Support\DeletionGuard;
use App\Filament\Support\FillsTranslatedFormData;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditIngredient extends EditRecord
{
    use FillsTranslatedFormData;
    use ValidatesIngredientAliases;

    protected ?bool $hasDatabaseTransactions = true;

    protected static string $resource = IngredientResource::class;

    protected function beforeSave(): void
    {
        $this->validateIngredientAliases();
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->before(DeletionGuard::single()),
        ];
    }
}
