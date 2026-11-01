<?php

namespace App\Services;

use App\Models\Unit;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Converts only between genuinely equivalent measurements. Mass and volume are never converted into each other
 * (that needs a per-ingredient density), and counted units such as piece, clove and pinch only equal themselves.
 */
class UnitConverter
{
    public const SCALE = 4;

    /**
     * Units share a family only if their amounts can be added together.
     */
    public function family(?Unit $unit): string
    {
        return match ($unit?->unit_type) {
            null => 'none',
            'mass' => 'mass',
            'volume' => 'volume',
            default => 'count:'.$unit->getKey(),
        };
    }

    public function canCombine(?Unit $a, ?Unit $b): bool
    {
        return $this->family($a) === $this->family($b);
    }

    public function toBase(string $quantity, Unit $unit): BigDecimal
    {
        return BigDecimal::of($quantity)->multipliedBy($unit->base_conversion);
    }

    public function fromBase(BigDecimal $base, Unit $unit): string
    {
        return (string) $base->dividedBy($unit->base_conversion, self::SCALE, RoundingMode::HalfUp);
    }

    /**
     * Returns null when the units are not equivalent, so callers cannot silently mix incompatible measurements.
     */
    public function convert(string $quantity, Unit $from, Unit $to): ?string
    {
        if (! $this->canCombine($from, $to)) {
            return null;
        }

        return $this->fromBase($this->toBase($quantity, $from), $to);
    }

    /**
     * The most readable unit for a total: the largest candidate not exceeding it, else the smallest candidate.
     *
     * @param  iterable<Unit>  $candidates  all of one family
     */
    public function readableUnit(BigDecimal $base, iterable $candidates): Unit
    {
        $sorted = collect($candidates)->sortBy(fn (Unit $unit): string => (string) $unit->base_conversion, SORT_NATURAL)->values();

        $fitting = $sorted->filter(fn (Unit $unit): bool => $base->isGreaterThanOrEqualTo($unit->base_conversion));

        return $fitting->last() ?? $sorted->first();
    }
}
