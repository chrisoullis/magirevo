<?php

namespace App\Enums;

enum RecipeVisibility: string
{
    case Private = 'private';
    case Shared = 'shared';
    case Public = 'public';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
