<?php

namespace App\Actions;

use App\Models\Media;
use App\Models\Recipe;
use Illuminate\Support\Facades\DB;

class RemoveRecipeMedia
{
    public function __construct(private DeleteMediaIfUnused $deleteMediaIfUnused) {}

    public function handle(Recipe $recipe, Media $media): void
    {
        DB::transaction(function () use ($recipe, $media): void {
            $recipe->media()->detach($media);
            $this->deleteMediaIfUnused->handle($media);
        });
    }
}
