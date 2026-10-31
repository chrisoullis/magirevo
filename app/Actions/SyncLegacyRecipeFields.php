<?php

namespace App\Actions;

use App\Models\Recipe;
use Illuminate\Support\Facades\Storage;

/**
 * Keeps the denormalised columns used by the public site in step with the structured recipe data.
 */
class SyncLegacyRecipeFields
{
    public function handle(Recipe $recipe): void
    {
        $recipe->unsetRelations();

        $cover = $recipe->media()->wherePivot('role', 'cover')->first()
            ?? $recipe->media()->first();

        $ingredients = $recipe->recipeIngredients()
            ->with(['ingredient.translations', 'unit.translations', 'translations'])
            ->orderBy('sort_order')
            ->get()
            ->map(function ($line): string {
                $quantity = $line->translate('en')?->quantity_text
                    ?? trim(rtrim(rtrim((string) $line->quantity, '0'), '.').' '.($line->unit?->translate('en')?->abbreviation ?? ''));

                return trim(implode(' ', array_filter([
                    $quantity,
                    $line->ingredient->translate('en')?->name,
                ])).($line->translate('en')?->preparation_note ? ', '.$line->translate('en')->preparation_note : ''));
            })
            ->all();

        $instructions = $recipe->steps()->with('translations')->get()
            ->map(fn ($step): string => (string) $step->translate('en')?->instruction)
            ->all();

        $recipe->forceFill([
            'category' => $recipe->categories()->with('translations')->get()->first()?->translate('en')?->name
                ?? $recipe->category
                ?: 'Uncategorised',
            'image_url' => $cover ? Storage::disk($cover->disk)->url($cover->file_path) : $recipe->image_url,
            'ingredients' => $ingredients,
            'instructions' => $instructions,
            'servings' => max(1, (int) round((float) $recipe->base_servings)),
        ])->saveQuietly();
    }
}
