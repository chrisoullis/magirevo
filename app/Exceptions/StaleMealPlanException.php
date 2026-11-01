<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when an edit was made against an out-of-date copy of a plan or entry.
 */
class StaleMealPlanException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The meal plan was changed by someone else.');
    }
}
