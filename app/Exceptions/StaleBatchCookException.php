<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a batch was changed by someone else after the editor loaded it.
 */
class StaleBatchCookException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The batch was changed by someone else.');
    }
}
