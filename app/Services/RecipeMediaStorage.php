<?php

namespace App\Services;

use App\Enums\RecipeVisibility;
use App\Models\Media;
use App\Models\Recipe;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Keeps uploaded recipe images on the public disk only while a public recipe (or category) uses them.
 * Everything else lives on the private disk and is delivered through the authorized media route.
 */
class RecipeMediaStorage
{
    public const PUBLIC_DISK = 'public';

    public const PRIVATE_DISK = 'local';

    public function diskFor(Recipe $recipe): string
    {
        return $recipe->derived_from_recipe_id === null && $recipe->visibility === RecipeVisibility::Public ? self::PUBLIC_DISK : self::PRIVATE_DISK;
    }

    /**
     * Moves each uploaded image of the recipe to the disk that matches its current exposure.
     */
    public function syncStorage(Recipe $recipe): void
    {
        $steps = $recipe->steps()->with('media')->get()->flatMap(fn ($step) => $step->media);

        $recipe->media()->get()->merge($steps)->unique('id')->each(function (Media $media): void {
            if (! $media->isLocal()) {
                return;
            }

            $target = $this->mustBePublic($media) ? self::PUBLIC_DISK : self::PRIVATE_DISK;

            if ($media->disk !== $target) {
                $this->move($media, $target);
            }
        });
    }

    private function mustBePublic(Media $media): bool
    {
        return $media->categories()->exists()
            || $media->recipes()->whereNull('derived_from_recipe_id')->where('visibility', RecipeVisibility::Public->value)->exists()
            || $media->recipeSteps()->whereHas('recipe', fn ($recipe) => $recipe->whereNull('derived_from_recipe_id')->where('visibility', RecipeVisibility::Public->value))->exists();
    }

    private function move(Media $media, string $target): void
    {
        $path = (string) $media->file_path;
        $source = $media->disk;

        if (! Storage::disk($source)->exists($path)) {
            Log::warning('media.relocation_skipped_missing_file', ['media_id' => $media->getKey(), 'target' => $target]);
            $media->forceFill(['disk' => $target])->save();

            return;
        }

        $stream = Storage::disk($source)->readStream($path);

        if (! is_resource($stream)) {
            throw new RuntimeException("Unable to read media {$media->getKey()} for relocation.");
        }

        $options = $target === self::PUBLIC_DISK ? ['visibility' => 'public'] : [];

        if (! Storage::disk($target)->writeStream($path, $stream, $options)) {
            throw new RuntimeException("Unable to write media {$media->getKey()} to the {$target} disk.");
        }

        $media->forceFill(['disk' => $target])->save();

        DB::afterCommit(fn (): bool => Storage::disk($source)->delete($path));
    }
}
