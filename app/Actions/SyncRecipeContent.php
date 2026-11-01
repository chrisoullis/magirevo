<?php

namespace App\Actions;

use App\Models\Media;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class SyncRecipeContent
{
    private const STEP_NUMBER_OFFSET = 10000;

    public function __construct(
        private SyncRecipeStepMedia $syncStepMedia,
        private DeleteMediaIfUnused $deleteMediaIfUnused,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $steps
     * @param  array<int, array<string, mixed>>  $ingredients
     */
    public function handle(Recipe $recipe, array $steps, array $ingredients): void
    {
        Gate::authorize('update', $recipe);
        $storedFiles = [];

        try {
            DB::transaction(function () use ($recipe, $steps, $ingredients, &$storedFiles): void {
                $this->syncSteps($recipe, array_values($steps), $storedFiles);
                $this->syncIngredients($recipe, array_values($ingredients));
            });
        } catch (Throwable $exception) {
            foreach ($storedFiles as $storedFile) {
                if (! Storage::disk($storedFile['disk'])->delete($storedFile['path'])) {
                    throw new RuntimeException('Unable to remove a step image after recipe content persistence failed.', previous: $exception);
                }
            }

            throw $exception;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array{disk: string, path: string}>  $storedFiles
     */
    private function syncSteps(Recipe $recipe, array $rows, array &$storedFiles): void
    {
        $keptIds = collect($rows)->pluck('id')->filter()->all();

        $removedSteps = $recipe->steps()->whereNotIn('id', $keptIds)->with('media')->get();

        foreach ($removedSteps as $removedStep) {
            $media = $removedStep->media;
            $removedStep->delete();
            $media->each(fn (Media $item) => $this->deleteMediaIfUnused->handle($item));
        }

        // Moves surviving steps out of the way so reordering cannot violate the unique step number.
        $recipe->steps()->increment('step_number', self::STEP_NUMBER_OFFSET);

        foreach ($rows as $index => $row) {
            $step = filled($row['id'] ?? null)
                ? $recipe->steps()->findOrFail($row['id'])
                : new RecipeStep(['recipe_id' => $recipe->id]);

            $step->fill([
                'step_number' => $index + 1,
                'duration_minutes' => filled($row['duration_minutes'] ?? null) ? (int) $row['duration_minutes'] : null,
                'en' => ['instruction' => $row['instruction_en']],
                'el' => ['instruction' => $row['instruction_el']],
            ])->save();

            $storedFiles = [
                ...$storedFiles,
                ...$this->syncStepMedia->handle(
                    $recipe,
                    $step,
                    $row['media_items'] ?? [],
                    filled($row['video_url'] ?? null) ? $row['video_url'] : null,
                    [
                        'en' => [
                            'alt_text' => $row['video_alt_text_en'] ?? null,
                            'caption' => $row['video_caption_en'] ?? null,
                        ],
                        'el' => [
                            'alt_text' => $row['video_alt_text_el'] ?? null,
                            'caption' => $row['video_caption_el'] ?? null,
                        ],
                    ],
                ),
            ];
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function syncIngredients(Recipe $recipe, array $rows): void
    {
        $keptIds = collect($rows)->pluck('id')->filter()->all();

        $recipe->recipeIngredients()->whereNotIn('id', $keptIds)->get()->each->delete();

        foreach ($rows as $index => $row) {
            $ingredient = filled($row['id'] ?? null)
                ? $recipe->recipeIngredients()->findOrFail($row['id'])
                : new RecipeIngredient(['recipe_id' => $recipe->id]);

            $ingredient->fill([
                'ingredient_id' => $row['ingredient_id'],
                'quantity' => filled($row['quantity'] ?? null) ? $row['quantity'] : null,
                'quantity_max' => filled($row['quantity_max'] ?? null) ? $row['quantity_max'] : null,
                'unit_id' => filled($row['unit_id'] ?? null) ? $row['unit_id'] : null,
                'scaling_mode' => $row['scaling_mode'] ?? 'linear',
                'is_optional' => (bool) ($row['is_optional'] ?? false),
                'sort_order' => $index,
                'en' => [
                    'preparation_note' => $this->nullIfBlank($row['preparation_note_en'] ?? null),
                    'quantity_text' => $this->nullIfBlank($row['quantity_text_en'] ?? null),
                ],
                'el' => [
                    'preparation_note' => $this->nullIfBlank($row['preparation_note_el'] ?? null),
                    'quantity_text' => $this->nullIfBlank($row['quantity_text_el'] ?? null),
                ],
            ])->save();
        }
    }

    private function nullIfBlank(?string $value): ?string
    {
        return filled($value) ? $value : null;
    }
}
