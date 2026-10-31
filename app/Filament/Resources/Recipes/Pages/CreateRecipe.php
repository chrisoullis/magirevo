<?php

namespace App\Filament\Resources\Recipes\Pages;

use App\Filament\Resources\Recipes\Pages\Concerns\SavesRecipeContent;
use App\Filament\Resources\Recipes\RecipeResource;
use App\Models\Recipe;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateRecipe extends CreateRecord
{
    use SavesRecipeContent;

    protected static string $resource = RecipeResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $data = $this->extractRecipeContent($data);

        return DB::transaction(fn (): Recipe => Recipe::query()->create([
            ...$data,
            'author_id' => auth()->id(),
            'status' => 'draft',
            'servings' => max(1, (int) round((float) $data['base_servings'])),
            'category' => '',
            'image_url' => '',
            'ingredients' => [],
            'instructions' => [],
        ]));
    }

    protected function afterCreate(): void
    {
        $this->persistRecipeContent($this->getRecord());
    }
}
