<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a template was changed or removed by someone else after the editor loaded it.
 */
class StaleTemplateException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The meal plan template was changed by someone else.');
    }
}
