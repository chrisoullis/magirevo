<?php

namespace App\Http\Resources;

use App\Services\CookingContentService;
use App\Services\CookingSessionProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Returned only after CookingSessionService has verified ownership, access and snapshot integrity. */
class CookingSessionResource extends JsonResource
{
    public function withResponse(Request $request, JsonResponse $response): void
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    public function toArray(Request $request): array
    {
        $revision = $this->resource->revision;
        $progress = app(CookingSessionProgress::class);
        $state = $progress->present($this->resource->state);
        foreach (['steps', 'timers', 'last_steps', 'checked_ingredients'] as $map) {
            $state[$map] = (object) $state[$map];
        }

        return [
            'id' => $this->id, 'recipe_id' => $this->recipe_id, 'revision_id' => $this->recipe_revision_id,
            'content_hash' => $this->content_hash, 'schema_version' => $revision->schema_version,
            'version' => $this->version, 'servings' => $this->servings, 'status' => $this->status,
            'server_time_ms' => now()->getTimestampMs(), 'state' => $state,
            ...app(CookingContentService::class)->present($revision, $this->servings, app()->getLocale()),
        ];
    }
}
