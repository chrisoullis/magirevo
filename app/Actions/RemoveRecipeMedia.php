<?php

namespace App\Actions;

use App\Models\Media;
use App\Models\Recipe;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class RemoveRecipeMedia
{
    public function handle(Recipe $recipe, Media $media): void
    {
        DB::transaction(function () use ($recipe, $media): void {
            $recipe->media()->detach($media);

            if ($media->recipes()->doesntExist() && $media->recipeSteps()->doesntExist()) {
                $disk = $media->disk;
                $path = $media->file_path;
                $isRemote = $media->isRemote();

                $media->delete();

                if (! $isRemote) {
                    DB::afterCommit(fn (): bool => Storage::disk($disk)->delete($path));
                }
            }
        });
    }
}
