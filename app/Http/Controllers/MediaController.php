<?php

namespace App\Http\Controllers;

use App\Models\Media;
use App\Models\Recipe;
use App\Models\RecipeRevision;
use App\Services\CookingSessionService;
use App\Services\RecipeAccessService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Delivers private recipe images. A file is served only when the current user may view a recipe that uses it;
 * every other case answers 404 so the existence of the file is not revealed.
 */
class MediaController extends Controller
{
    public function show(Media $media, RecipeAccessService $access): StreamedResponse
    {
        abort_unless($media->isPrivate() && Storage::disk($media->disk)->exists((string) $media->file_path), 404);

        $user = Auth::user();

        $ingredientImages = $media->ingredientImages()->with('ingredient')->get()
            ->merge($media->ingredientThumbnails()->with('ingredient')->get());

        if (! $media->recipes()->exists() && ! $media->recipeSteps()->exists() && ! $media->isRetainedByRevision()
            && $ingredientImages->contains(fn ($image): bool => $user?->can('view', $image->ingredient) ?? false)) {
            return Storage::disk($media->disk)->response((string) $media->file_path, null, [
                'Cache-Control' => 'private, no-store',
                'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            ]);
        }

        $recipes = Recipe::query()->withTrashed()
            ->where(fn ($query) => $query
                ->whereHas('media', fn ($related) => $related->whereKey($media->getKey()))
                ->orWhereHas('steps.media', fn ($related) => $related->whereKey($media->getKey())))
            ->get();

        $allowed = $recipes->first(fn (Recipe $recipe): bool => ! $recipe->trashed() && $access->canView($user, $recipe));

        if ($allowed === null && $user !== null) {
            $revision = RecipeRevision::query()->whereHas('media', fn ($references) => $references->where('media_id', $media->id))
                ->with('recipe')->get()->first(fn ($revision): bool => ! $revision->recipe->trashed() && $user->can('view', $revision));
            $allowed = $revision?->recipe;
        }

        if ($allowed === null && $user !== null && app(CookingSessionService::class)->allowsMedia($user, $media)) {
            return Storage::disk($media->disk)->response((string) $media->file_path, null, [
                'Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            ]);
        }
        abort_if($allowed === null, 404);

        $access->recordModerationAccess($user, $allowed, 'media');

        return Storage::disk($media->disk)->response((string) $media->file_path, null, [
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
        ]);
    }
}
