<?php

namespace App\Services;

use App\Models\Unit;
use App\Support\ConsolidatedQuantity;
use App\Support\QuantityLine;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;

/**
 * Totals ingredient amounts per ingredient. Lines are merged only when their units are equivalent:
 * mass with mass, volume with volume, and counted units only with the same unit. Amounts that cannot be merged
 * stay as separate rows, optional amounts never inflate the required total, and lines with no amount are listed once.
 */
class IngredientConsolidator
{
    private const READABLE_UNITS = ['mass' => ['g', 'kg'], 'volume' => ['ml', 'l']];

    public function __construct(private UnitConverter $converter) {}

    /**
     * @param  iterable<QuantityLine>  $lines
     * @return list<ConsolidatedQuantity>
     */
    public function consolidate(iterable $lines): array
    {
        $groups = [];

        foreach ($lines as $line) {
            $family = $line->quantity === null ? 'unquantified' : $this->converter->family($line->unit);
            $groups[$line->ingredientId.'|'.$family.'|'.($line->isOptional ? 'optional' : 'required')][] = [$family, $line];
        }

        ksort($groups, SORT_NATURAL);

        $readable = null;
        $result = [];

        foreach ($groups as $entries) {
            $family = $entries[0][0];
            $groupLines = array_column($entries, 1);

            $result[] = match ($family) {
                'unquantified' => $this->unquantified($groupLines),
                'mass', 'volume' => $this->measured($groupLines, $family, $readable ??= $this->loadReadableUnits()),
                default => $this->sameUnit($groupLines),
            };
        }

        usort($result, fn (ConsolidatedQuantity $a, ConsolidatedQuantity $b): int => [$a->ingredientId, $a->isOptional] <=> [$b->ingredientId, $b->isOptional]);

        return $result;
    }

    /**
     * @param  list<QuantityLine>  $lines
     */
    private function unquantified(array $lines): ConsolidatedQuantity
    {
        return new ConsolidatedQuantity($lines[0]->ingredientId, $lines[0]->unit, null, null, $lines[0]->isOptional, $this->sources($lines), false);
    }

    /**
     * Counted units and unit-less amounts: plain addition.
     *
     * @param  list<QuantityLine>  $lines
     */
    private function sameUnit(array $lines): ConsolidatedQuantity
    {
        [$total, $max] = $this->sum($lines, fn (QuantityLine $line, string $quantity): BigDecimal => BigDecimal::of($quantity));

        return $this->build($lines, $lines[0]->unit, $total, $max, false);
    }

    /**
     * Mass and volume: keep the unit when every line already uses it, otherwise add in base units and
     * express the total in the most readable metric unit.
     *
     * @param  list<QuantityLine>  $lines
     * @param  Collection<string, Collection<int, Unit>>  $readable
     */
    private function measured(array $lines, string $family, Collection $readable): ConsolidatedQuantity
    {
        $unitIds = array_unique(array_map(fn (QuantityLine $line): int => $line->unit->getKey(), $lines));

        if (count($unitIds) === 1) {
            return $this->sameUnit($lines);
        }

        [$base, $baseMax] = $this->sum($lines, fn (QuantityLine $line, string $quantity): BigDecimal => $this->converter->toBase($quantity, $line->unit));

        $candidates = $readable->get($family, collect());

        if ($candidates->isEmpty()) {
            $candidates = collect($lines)->map(fn (QuantityLine $line): Unit => $line->unit)->unique(fn (Unit $unit): int => $unit->getKey());
        }

        $unit = $this->converter->readableUnit($base, $candidates);

        return $this->build(
            $lines,
            $unit,
            BigDecimal::of($this->converter->fromBase($base, $unit)),
            BigDecimal::of($this->converter->fromBase($baseMax, $unit)),
            true,
        );
    }

    /**
     * @param  list<QuantityLine>  $lines
     * @param  callable(QuantityLine, string): BigDecimal  $normalise
     * @return array{0: BigDecimal, 1: BigDecimal}
     */
    private function sum(array $lines, callable $normalise): array
    {
        $total = BigDecimal::zero();
        $max = BigDecimal::zero();

        foreach ($lines as $line) {
            $total = $total->plus($normalise($line, $line->quantity));
            $max = $max->plus($normalise($line, $line->quantityMax ?? $line->quantity));
        }

        return [$total, $max];
    }

    /**
     * @param  list<QuantityLine>  $lines
     */
    private function build(array $lines, ?Unit $unit, BigDecimal $total, BigDecimal $max, bool $converted): ConsolidatedQuantity
    {
        $total = $total->toScale(UnitConverter::SCALE, RoundingMode::HalfUp);
        $max = $max->toScale(UnitConverter::SCALE, RoundingMode::HalfUp);

        return new ConsolidatedQuantity(
            $lines[0]->ingredientId,
            $unit,
            (string) $total,
            $max->isGreaterThan($total) ? (string) $max : null,
            $lines[0]->isOptional,
            $this->sources($lines),
            $converted,
        );
    }

    /**
     * @param  list<QuantityLine>  $lines
     * @return list<mixed>
     */
    private function sources(array $lines): array
    {
        return array_values(array_filter(array_map(fn (QuantityLine $line): mixed => $line->source, $lines), fn (mixed $source): bool => $source !== null));
    }

    /**
     * @return Collection<string, Collection<int, Unit>>
     */
    private function loadReadableUnits(): Collection
    {
        $units = Unit::query()->whereIn('code', array_merge(...array_values(self::READABLE_UNITS)))->get();

        return collect(self::READABLE_UNITS)->map(fn (array $codes): Collection => $units->whereIn('code', $codes)->values());
    }
}
