<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a shopping item was changed by someone else after the editor loaded it.
 */
class StaleShoppingListException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The shopping list item was changed by someone else.');
    }
}
