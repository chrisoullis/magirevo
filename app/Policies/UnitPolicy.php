<?php

namespace App\Policies;

use App\Models\Unit;

class UnitPolicy extends LookupPolicy
{
    protected function model(): string
    {
        return Unit::class;
    }
}
