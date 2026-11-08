<?php

namespace App\Policies;

use App\Models\CookingSession;
use App\Models\User;
use App\Services\CookingContentService;

class CookingSessionPolicy
{
    public function __construct(private CookingContentService $content) {}

    public function view(User $user, CookingSession $session): bool
    {
        $recipe = $session->recipe;
        $revision = $session->revision;

        return $user->hasVerifiedEmail() && (int) $session->user_id === (int) $user->id
            && $recipe !== null && $revision !== null
            && hash_equals($session->content_hash, $revision->content_hash)
            && $revision->media_snapshot_hash !== null
            && hash_equals($session->media_snapshot_hash, $revision->media_snapshot_hash)
            && $this->content->allows($recipe, $revision, $user, $session->access_scope === 'public');
    }

    public function update(User $user, CookingSession $session): bool
    {
        return $this->view($user, $session);
    }
}
