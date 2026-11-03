<?php

namespace App\Policies;

use App\Models\IngredientCatalogGroup;

class IngredientCatalogGroupPolicy extends LookupPolicy
{
    protected function model(): string
    {
        return IngredientCatalogGroup::class;
    }
}
