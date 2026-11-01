<?php

namespace App\Policies;

use App\Models\Difficulty;

class DifficultyPolicy extends LookupPolicy
{
    protected function model(): string
    {
        return Difficulty::class;
    }
}
