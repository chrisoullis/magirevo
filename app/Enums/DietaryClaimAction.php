<?php

namespace App\Enums;

enum DietaryClaimAction: string
{
    case Suggested = 'suggested';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Suspended = 'suspended';
    case Invalidated = 'invalidated';
    case Flagged = 'flagged';
}
