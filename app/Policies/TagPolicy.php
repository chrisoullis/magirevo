<?php

namespace App\Policies;

use App\Models\Tag;

class TagPolicy extends LookupPolicy
{
    protected function model(): string
    {
        return Tag::class;
    }
}
