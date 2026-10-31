<?php

namespace App\Filament\Resources\Recipes\Pages\Concerns;

use App\Actions\SyncRecipeContent;
use App\Models\Recipe;
use Illuminate\Support\Arr;

trait SavesRecipeContent
{
    /**
     * @var array{steps: array<int, array<string, mixed>>, ingredients: array<int, array<string, mixed>>}
     */
    private array $recipeContent = ['steps' => [], 'ingredients' => []];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function extractRecipeContent(array $data): array
    {
        $this->recipeContent = [
            'steps' => $data['steps_data'] ?? [],
            'ingredients' => $data['ingredients_data'] ?? [],
        ];

        return Arr::except($data, ['steps_data', 'ingredients_data']);
    }

    private function persistRecipeContent(Recipe $recipe): void
    {
        app(SyncRecipeContent::class)->handle($recipe, $this->recipeContent['steps'], $this->recipeContent['ingredients']);
    }
}
