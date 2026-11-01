<?php

namespace App\Policies;

use App\Models\ScalingMode;

class ScalingModePolicy extends LookupPolicy
{
    protected function model(): string
    {
        return ScalingMode::class;
    }
}
