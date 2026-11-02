<?php

namespace App\Enums;

enum DietaryCompatibility: string
{
    case Compatible = 'compatible';
    case Incompatible = 'incompatible';
    case Unknown = 'unknown';
}
