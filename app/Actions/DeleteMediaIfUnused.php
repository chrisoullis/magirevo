<?php

namespace App\Actions;

use App\Models\Media;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes a media row and its file only when no recipe, recipe step or category still references it.
 */
class DeleteMediaIfUnused
{
    public function handle(Media $media): bool
    {
        return DB::transaction(function () use ($media): bool {
            if ($media->isInUse()) {
                return false;
            }

            $disk = $media->disk;
            $path = $media->file_path;
            $isRemote = $media->isRemote();

            $media->delete();

            if (! $isRemote) {
                DB::afterCommit(fn (): bool => Storage::disk($disk)->delete($path));
            }

            return true;
        });
    }
}
