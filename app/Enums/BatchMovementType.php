<?php

namespace App\Enums;

/**
 * Ledger entry kinds. Free portions = prepared - reserved - consumed.
 */
enum BatchMovementType: string
{
    case Prepared = 'prepared';
    case Reserved = 'reserved';
    case Released = 'released';
    case Consumed = 'consumed';
}
