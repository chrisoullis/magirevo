<?php

namespace App\Services;

use App\Models\RecipeIngredient;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ServingAdjustmentService
{
    /**
     * @return array{quantity: ?string, quantity_max: ?string, was_scaled: bool}
     */
    public function adjust(RecipeIngredient $recipeIngredient, string|int $requestedServings): array
    {
        $requestedServings = (string) $requestedServings;

        if (
            preg_match('/^\d+(?:\.\d+)?$/D', $requestedServings) !== 1
            || preg_match('/[1-9]/', $requestedServings) !== 1
        ) {
            throw new InvalidArgumentException('Requested servings must be a positive decimal number.');
        }

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
}
