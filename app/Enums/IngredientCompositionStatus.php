<?php

namespace App\Enums;

enum IngredientCompositionStatus: string
{
    case Complete = 'complete';
    case Incomplete = 'incomplete';
    case Unknown = 'unknown';
}
