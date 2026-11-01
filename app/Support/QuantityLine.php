<?php

namespace App\Support;

use App\Models\Unit;

/**
 * One scaled ingredient amount from one source (for example a planned meal), before consolidation.
 */
final readonly class QuantityLine
{
    public function __construct(
        public int $ingredientId,
        public ?Unit $unit,
        public ?string $quantity,
        public ?string $quantityMax = null,
        public bool $isOptional = false,
        public mixed $source = null,
    ) {}
}
