<?php

namespace App\Filament\Resources\Ingredients\Pages;

use Illuminate\Validation\ValidationException;

trait ValidatesIngredientAliases
{
    protected function validateIngredientAliases(): void
    {
        $seen = [];
        foreach ($this->data['aliases'] ?? [] as $row) {
            $alias = trim($row['alias'] ?? '');
            $key = ($row['locale'] ?? '').':'.mb_strtolower($alias);
            if ($alias !== ($row['alias'] ?? '') || isset($seen[$key])) {
                throw ValidationException::withMessages(['data.aliases' => 'Aliases must be trimmed and unique within each language.']);
            }
            $seen[$key] = true;
        }
    }
}
