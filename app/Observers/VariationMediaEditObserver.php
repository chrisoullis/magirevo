<?php

namespace App\Observers;

use App\Models\Media;
use App\Models\Recipe;
use App\Models\RecipeRevisionAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Tracks administrative media edits as well as edits made through the member editor. */
class VariationMediaEditObserver
{
    public function saved(Model $model): void
    {
        $actorId = Auth::id();
        $mediaId = $model instanceof Media ? $model->id : $model->media_id;
        if ($actorId === null) {
            return;
        }
        // Uploads attach their media after creating the row; resolve usage only after that transaction commits.
        DB::afterCommit(function () use ($actorId, $mediaId): void {
            $recipes = Recipe::query()->whereNotNull('derived_from_recipe_id')->where(fn ($q) => $q
                ->whereHas('media', fn ($media) => $media->whereKey($mediaId))
                ->orWhereHas('steps.media', fn ($media) => $media->whereKey($mediaId)))->pluck('id');
            foreach ($recipes as $id) {
                RecipeRevisionAudit::query()->create(['recipe_id' => $id, 'actor_id' => $actorId, 'action' => 'variation_edited']);
            }
        });
    }
}
