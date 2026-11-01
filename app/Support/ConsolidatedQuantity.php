<?php

namespace App\Support;

use App\Models\Unit;

/**
 * A total for one ingredient in one kind of measurement. A null quantity means "no amount given" (for example "to taste").
 */
final readonly class ConsolidatedQuantity
{
    /**
     * @param  list<mixed>  $sources
     */
    public function __construct(
        public int $ingredientId,
        public ?Unit $unit,
        public ?string $quantity,
        public ?string $quantityMax,
        public bool $isOptional,
        public array $sources,
        public bool $converted,
    ) {}
}
