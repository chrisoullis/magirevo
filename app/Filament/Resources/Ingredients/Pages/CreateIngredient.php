<?php

namespace App\Filament\Resources\Ingredients\Pages;

use App\Filament\Resources\Ingredients\IngredientResource;
use Filament\Resources\Pages\CreateRecord;

class CreateIngredient extends CreateRecord
{
    use ValidatesIngredientAliases;

    protected ?bool $hasDatabaseTransactions = true;

    protected static string $resource = IngredientResource::class;

    protected function beforeCreate(): void
    {
        $this->validateIngredientAliases();
    }
}
