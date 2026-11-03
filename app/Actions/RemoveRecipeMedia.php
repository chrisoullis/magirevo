<?php

namespace App\Actions;

use App\Models\Media;
use App\Models\Recipe;
use App\Models\RecipeRevisionAudit;
use Illuminate\Support\Facades\DB;

class RemoveRecipeMedia
{
    public function __construct(private DeleteMediaIfUnused $deleteMediaIfUnused) {}

    public function handle(Recipe $recipe, Media $media): void
    {
        DB::transaction(function () use ($recipe, $media): void {
            $recipe->media()->detach($media);
            if ($recipe->derived_from_recipe_id !== null && auth()->id() !== null) {
                RecipeRevisionAudit::query()->create(['recipe_id' => $recipe->id, 'actor_id' => auth()->id(), 'action' => 'variation_edited']);
            }
            $this->deleteMediaIfUnused->handle($media);
        });
    }
}
