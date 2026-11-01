<?php

namespace App\Policies;

use App\Models\Category;

class CategoryPolicy extends LookupPolicy
{
    protected function model(): string
    {
        return Category::class;
    }
}
