<?php

namespace App\Policies;

use App\Models\Ingredient;

class IngredientPolicy extends LookupPolicy
{
    protected function model(): string
    {
        return Ingredient::class;
    }
}
