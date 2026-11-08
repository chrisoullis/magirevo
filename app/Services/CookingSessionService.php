<?php

namespace App\Services;

use App\Models\CookingSession;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\RecipeRevision;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CookingSessionService
{
    public function __construct(private CookingSessionProgress $progress) {}

    public function start(User $actor, int $recipeId, string $requestKey, mixed $servings = null, ?string $expectedHash = null): CookingSession
    {
        $this->ensureEnabled();
        Validator::make(['request_key' => $requestKey], ['request_key' => ['required', 'uuid']])->validate();

        return DB::transaction(function () use ($actor, $recipeId, $requestKey, $servings, $expectedHash): CookingSession {
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            abort_unless($actor->hasVerifiedEmail(), 403);
            $existing = CookingSession::query()->where('user_id', $actor->id)->where('request_key', $requestKey)->first();
            if ($existing !== null) {
                abort_unless((int) $existing->recipe_id === $recipeId, 409, __('cooking.session_conflict'));

                return $this->read($actor, $existing->id);
            }
            $recipe = Recipe::query()->lockForUpdate()->findOrFail($recipeId);
            $recipe->shares()->active()->where('user_id', $actor->id)->sharedLock()->get();
            abort_unless($recipe->status !== 'archived' && Gate::forUser($actor)->allows('view', $recipe), 404);
            $public = $recipe->isPubliclyAccessible();
            $revision = $public
                ? RecipeRevision::query()->where('recipe_id', $recipeId)->where('status', 'published')->findOrFail($recipe->current_revision_id)
                : app(RecipeRevisionService::class)->captureComponentSnapshot($actor, $recipe);
            abort_if($expectedHash !== null && ! hash_equals($revision->content_hash, $expectedHash), 409, __('cooking.content_changed'));
            $servings ??= $revision->payload['recipe']['base_servings'] ?? null;
            $servings = $this->servings($servings);
            $session = (new CookingSession)->forceFill([
                'user_id' => $actor->id, 'recipe_id' => $recipeId, 'recipe_revision_id' => $revision->id,
                'request_key' => $requestKey, 'content_hash' => $revision->content_hash,
                'media_snapshot_hash' => $revision->media_snapshot_hash, 'access_scope' => $public ? 'public' : 'restricted',
                'servings' => $servings, 'state' => $this->progress->initial($revision->payload), 'status' => 'active', 'version' => 1,
            ]);
            $session->setRelation('recipe', $recipe)->setRelation('revision', $revision);
            abort_unless(Gate::forUser($actor)->allows('view', $session), 404);
            $session->save();
            app(RecipeAccessService::class)->recordModerationAccess($actor, $recipe, 'cooking_session');

            return $session->refresh();
        });
    }

    public function read(User $actor, string $id): CookingSession
    {
        $this->ensureEnabled();
        abort_unless(Str::isUuid($id), 404);
        $session = CookingSession::query()->where('user_id', $actor->id)->with(['recipe', 'revision'])->findOrFail($id);
        abort_unless(Gate::forUser($actor)->allows('view', $session), 404);

        return $session;
    }

    public function latest(User $actor, int $recipeId): ?CookingSession
    {
        $this->ensureEnabled();
        abort_unless($actor->hasVerifiedEmail(), 403);
        $recipe = Recipe::query()->findOrFail($recipeId);
        abort_unless($recipe->status !== 'archived' && Gate::forUser($actor)->allows('view', $recipe), 404);
        $session = CookingSession::query()->where('user_id', $actor->id)->where('recipe_id', $recipeId)->where('status', 'active')
            ->orderByDesc('updated_at')->orderByDesc('id')->first();

        return $session === null ? null : $this->read($actor, $session->id);
    }

    public function update(User $actor, string $id, int $expectedVersion, array $event): CookingSession
    {
        $this->ensureEnabled();
        abort_unless(Str::isUuid($id), 404);
        $owned = CookingSession::query()->where('user_id', $actor->id)->findOrFail($id);

        return DB::transaction(function () use ($actor, $owned, $expectedVersion, $event): CookingSession {
            // Match the publisher's recipe-first lock order and serialize withdrawal with progress changes.
            $recipe = Recipe::withTrashed()->lockForUpdate()->findOrFail($owned->recipe_id);
            $session = CookingSession::query()->where('user_id', $actor->id)->lockForUpdate()->with('revision')->findOrFail($owned->id);
            $session->setRelation('recipe', $recipe);
            $recipe->shares()->active()->where('user_id', $actor->id)->sharedLock()->get();
            abort_unless(Gate::forUser($actor)->allows('update', $session), 404);
            abort_unless($session->version === $expectedVersion, 409, __('cooking.session_conflict'));
            Validator::make(['event' => $event], [
                'event' => ['required', 'array:action,step,status,ingredient,checked,size,duration_seconds,servings,context'],
                'event.action' => ['required', 'string'],
            ])->validate();
            $state = $session->state;
            $servings = $session->servings;
            if ($event['action'] === 'servings') {
                Validator::make($event, ['context' => ['prohibited']])->validate();
                $servings = $this->servings($event['servings'] ?? null);
            } else {
                $state = $this->progress->apply($state, $session->revision->payload, $event);
            }
            $status = $this->progress->completed($state) ? 'completed' : 'active';
            if ($state != $session->state || $servings !== $session->servings || $status !== $session->status) {
                $session->forceFill(['state' => $state, 'servings' => $servings, 'status' => $status, 'version' => $session->version + 1])->save();
            }

            return $session->refresh();
        });
    }

    /** Only session owners with currently valid access may read an otherwise historical private media asset. */
    public function allowsMedia(User $actor, Media $media): bool
    {
        if (! config('cooking.sessions_enabled') || ! Schema::hasTable('cooking_sessions')) {
            return false;
        }
        $sessions = CookingSession::query()->where('user_id', $actor->id)
            ->whereHas('revision.media', fn ($query) => $query->where('media_id', $media->id))->with(['recipe', 'revision'])->cursor();
        foreach ($sessions as $session) {
            if (Gate::forUser($actor)->allows('view', $session)) {
                return true;
            }
        }

        return false;
    }

    private function servings(mixed $value): string
    {
        $validated = Validator::make(['servings' => $value], [
            'servings' => ['required', 'numeric', 'decimal:0,2', 'between:0.01,1000'],
        ])->validate();

        return number_format((float) $validated['servings'], 2, '.', '');
    }

    private function ensureEnabled(): void
    {
        abort_unless(config('cooking.sessions_enabled') && Schema::hasTable('cooking_sessions'), 404);
    }
}
