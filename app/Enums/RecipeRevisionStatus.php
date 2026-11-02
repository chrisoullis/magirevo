<?php

namespace App\Enums;

enum RecipeRevisionStatus: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case Rejected = 'rejected';
    case Published = 'published';
    case Superseded = 'superseded';

    public function isOpen(): bool
    {
        return $this === self::Draft || $this === self::InReview;
    }
}
