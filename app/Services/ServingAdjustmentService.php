<?php

namespace App\Services;

use App\Models\RecipeIngredient;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ServingAdjustmentService
{
    /**
     * @return array{quantity: ?string, quantity_max: ?string, was_scaled: bool}
     */
    public function adjust(RecipeIngredient $recipeIngredient, string|int $requestedServings): array
    {
        $requestedServings = $this->validatedServings($requestedServings);

        if ($recipeIngredient->scaling_mode !== 'linear') {
            return [
                'quantity' => $recipeIngredient->quantity,
                'quantity_max' => $recipeIngredient->quantity_max,
                'was_scaled' => false,
            ];
        }

        if (! $recipeIngredient->relationLoaded('recipe')) {
            $recipeIngredient->load('recipe');
        }

        $baseServings = $recipeIngredient->recipe?->base_servings;

        if (blank($baseServings) || preg_match('/[1-9]/', (string) $baseServings) !== 1) {
            throw new InvalidArgumentException('The recipe must have positive base servings before quantities can be adjusted.');
        }

        $adjustedQuantities = DB::selectOne(
            <<<'SQL'
                SELECT
                    ROUND(CAST(? AS NUMERIC) * CAST(? AS NUMERIC) / CAST(? AS NUMERIC), 4)::TEXT AS quantity,
                    ROUND(CAST(? AS NUMERIC) * CAST(? AS NUMERIC) / CAST(? AS NUMERIC), 4)::TEXT AS quantity_max
            SQL,
            [
                $recipeIngredient->quantity,
                $requestedServings,
                $baseServings,
                $recipeIngredient->quantity_max,
                $requestedServings,
                $baseServings,
            ],
        );

        return [
            'quantity' => $adjustedQuantities->quantity,
            'quantity_max' => $adjustedQuantities->quantity_max,
            'was_scaled' => true,
        ];
    }

    /**
     * Scales many ingredient lines (typically from several recipes or several planned meals) in one query.
     * Each request pairs a line with the servings wanted for that line; results keep the request keys, and
     * match adjust() exactly. Lines should have their recipe relation loaded, otherwise it is loaded in one batch.
     *
     * @param  array<array-key, array{0: RecipeIngredient, 1: string|int}>  $requests
     * @return array<array-key, array{quantity: ?string, quantity_max: ?string, was_scaled: bool}>
     */
    public function adjustMany(array $requests): array
    {
        $results = [];
        $linear = [];

        $lines = array_map(fn (array $request): RecipeIngredient => $request[0], $requests);
        (new Collection(array_values($lines)))->loadMissing('recipe');

        foreach ($requests as $key => [$line, $servings]) {
            $servings = $this->validatedServings($servings);

            if ($line->scaling_mode !== 'linear') {
                $results[$key] = ['quantity' => $line->quantity, 'quantity_max' => $line->quantity_max, 'was_scaled' => false];

                continue;
            }

            $baseServings = $line->recipe?->base_servings;

            if (blank($baseServings) || preg_match('/[1-9]/', (string) $baseServings) !== 1) {
                throw new InvalidArgumentException('The recipe must have positive base servings before quantities can be adjusted.');
            }

            $linear[$key] = [$line->quantity, $line->quantity_max, $servings, (string) $baseServings];
        }

        foreach (array_chunk($linear, 2000, true) as $chunk) {
            $keys = array_keys($chunk);
            $placeholders = implode(', ', array_fill(0, count($chunk), '(?::INT, ?::NUMERIC, ?::NUMERIC, ?::NUMERIC, ?::NUMERIC)'));
            $bindings = [];

            foreach ($keys as $position => $key) {
                [$quantity, $quantityMax, $servings, $base] = $chunk[$key];
                array_push($bindings, $position, $quantity, $quantityMax, $servings, $base);
            }

            $rows = DB::select(
                <<<SQL
                    SELECT
                        position,
                        ROUND(quantity * servings / base, 4)::TEXT AS quantity,
                        ROUND(quantity_max * servings / base, 4)::TEXT AS quantity_max
                    FROM (VALUES {$placeholders}) AS lines (position, quantity, quantity_max, servings, base)
                SQL,
                $bindings,
            );

            foreach ($rows as $row) {
                $results[$keys[$row->position]] = ['quantity' => $row->quantity, 'quantity_max' => $row->quantity_max, 'was_scaled' => true];
            }
        }

        return array_replace(array_fill_keys(array_keys($requests), null), $results);
    }

    private function validatedServings(string|int $requestedServings): string
    {
        $requestedServings = (string) $requestedServings;

        if (
            preg_match('/^\d+(?:\.\d+)?$/D', $requestedServings) !== 1
            || preg_match('/[1-9]/', $requestedServings) !== 1
        ) {
            throw new InvalidArgumentException('Requested servings must be a positive decimal number.');
        }

        return $requestedServings;
    }
}
