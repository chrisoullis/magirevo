<?php

namespace App\Enums;

enum DietaryClaimStatus: string
{
    case Suggested = 'suggested';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Suspended = 'suspended';
    case Invalidated = 'invalidated';
}
