<?php

namespace App\Enums;

/**
 * Editorial workflow state. Deliberately separate from RecipeVisibility, which controls who may read a recipe.
 */
enum RecipeStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Published = 'published';
    case Archived = 'archived';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
