<?php

namespace App\Http\Controllers;

use App\Http\Resources\CookingSessionResource;
use App\Models\Recipe;
use App\Models\RecipeRevision;
use App\Services\CookingContentService;
use App\Services\CookingSessionProgress;
use App\Services\CookingSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CookingSessionController extends Controller
{
    /** Read-only, public-only snapshot endpoint; guests never create database sessions. */
    public function content(Request $request, CookingContentService $content, string $locale, int $recipe): JsonResponse
    {
        abort_unless(config('cooking.sessions_enabled'), 404);
        $data = $request->validate(['revision' => ['nullable', 'integer', 'min:1'], 'servings' => ['nullable', 'numeric', 'decimal:0,2', 'between:0.01,1000']]);
        $recipe = Recipe::query()->findOrFail($recipe);
        $revision = RecipeRevision::query()->where('recipe_id', $recipe->id)->findOrFail($data['revision'] ?? $recipe->current_revision_id);
        abort_unless($content->allows($recipe, $revision, null, true), 404);
        $servings = (string) ($data['servings'] ?? $revision->payload['recipe']['base_servings']);

        return response()->json(['data' => ['id' => null, 'state' => app(CookingSessionProgress::class)->initial($revision->payload),
            ...$content->present($revision, $servings, $locale)]])
            ->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    public function store(Request $request, CookingSessionService $sessions, string $locale, int $recipe): CookingSessionResource
    {
        $data = $request->validate([
            'request_key' => ['required', 'uuid'], 'servings' => ['nullable', 'numeric', 'decimal:0,2', 'between:0.01,1000'],
            'content_hash' => ['nullable', 'string', 'size:64'],
        ]);

        return new CookingSessionResource($sessions->start($request->user(), $recipe, $data['request_key'], $data['servings'] ?? null, $data['content_hash'] ?? null));
    }

    public function latest(Request $request, CookingSessionService $sessions, string $locale, int $recipe): CookingSessionResource|JsonResponse
    {
        $session = $sessions->latest($request->user(), $recipe);

        return $session === null ? response()->json(['data' => null])->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow, noarchive') : new CookingSessionResource($session);
    }

    public function show(Request $request, CookingSessionService $sessions, string $locale, string $session): CookingSessionResource
    {
        return new CookingSessionResource($sessions->read($request->user(), $session));
    }

    public function update(Request $request, CookingSessionService $sessions, string $locale, string $session): CookingSessionResource
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'event' => ['required', 'array']]);

        return new CookingSessionResource($sessions->update($request->user(), $session, (int) $data['version'], $data['event']));
    }
}
