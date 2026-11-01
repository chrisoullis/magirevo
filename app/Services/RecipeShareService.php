<?php

namespace App\Services;

use App\Enums\RecipeVisibility;
use App\Models\Recipe;
use App\Models\RecipeShare;
use App\Models\User;
use App\Notifications\RecipeSharedWithYou;
use App\Support\AppPermissions;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * The only place that creates, reactivates or revokes recipe shares.
 * Every method authorizes the acting user itself, so callers (Livewire, controllers) cannot skip the check.
 */
class RecipeShareService
{
    private const MAX_INVITATIONS_PER_HOUR = 20;

    public function __construct(private RecipePublishingService $publishing, private AuditLogger $audit, private RecipeQuotaService $quota) {}

    /**
     * Moves a private recipe to "shared" so grants become active. Public recipes are already visible to everyone.
     */
    public function enableSharing(User $owner, Recipe $recipe): Recipe
    {
        Gate::forUser($owner)->authorize('share', $recipe);
        Gate::forUser($owner)->authorize('changeVisibility', [$recipe, RecipeVisibility::Shared]);

        if ($recipe->visibility !== RecipeVisibility::Private && $recipe->visibility !== RecipeVisibility::Shared) {
            throw ValidationException::withMessages(['email' => __('sharing.errors.public')]);
        }

        $this->publishing->changeVisibility($recipe, RecipeVisibility::Shared);
        $this->audit->record('recipe_sharing_enabled', ['recipe_id' => $recipe->getKey()]);

        return $recipe;
    }

    /**
     * Back to private. Grants are kept but inactive, and become active again if the recipe is re-shared.
     */
    public function stopSharing(User $owner, Recipe $recipe): Recipe
    {
        Gate::forUser($owner)->authorize('revokeShare', $recipe);

        if ($recipe->visibility === RecipeVisibility::Shared) {
            $this->publishing->changeVisibility($recipe, RecipeVisibility::Private);
            $this->audit->record('recipe_sharing_suspended', ['recipe_id' => $recipe->getKey()]);
        }

        return $recipe;
    }

    public function share(User $owner, Recipe $recipe, string $email, string $locale): RecipeShare
    {
        Gate::forUser($owner)->authorize('share', $recipe);

        if ($recipe->visibility !== RecipeVisibility::Shared) {
            throw ValidationException::withMessages(['email' => __('sharing.errors.not_shared')]);
        }

        $key = 'recipe-share:'.$owner->getKey();

        if (RateLimiter::tooManyAttempts($key, self::MAX_INVITATIONS_PER_HOUR)) {
            throw ValidationException::withMessages(['email' => __('sharing.errors.too_many')]);
        }

        // Failed lookups count too, so the form cannot be used to probe which addresses are registered.
        RateLimiter::hit($key, 3600);

        $recipient = User::query()->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])->first();

        if ($recipient === null || ! $this->canReceive($recipient, $recipe)) {
            throw ValidationException::withMessages(['email' => __('sharing.errors.recipient')]);
        }

        [$share, $isNew] = DB::transaction(fn (): array => $this->grant($owner, $recipe, $recipient));

        if ($isNew) {
            $this->audit->record('recipe_shared', [
                'recipe_id' => $recipe->getKey(),
                'user_id' => $recipient->getKey(),
                'share_id' => $share->getKey(),
            ]);

            $recipient->notify(new RecipeSharedWithYou($share, $locale));
        }

        return $share;
    }

    public function revoke(User $actor, RecipeShare $share): RecipeShare
    {
        $recipe = $share->recipe()->withTrashed()->firstOrFail();

        Gate::forUser($actor)->authorize('revokeShare', $recipe);

        $revoked = DB::transaction(function () use ($share): bool {
            $locked = RecipeShare::query()->lockForUpdate()->findOrFail($share->getKey());

            if ($locked->revoked_at !== null) {
                return false;
            }

            $locked->forceFill(['revoked_at' => now()])->save();

            return true;
        });

        if ($revoked) {
            $this->audit->record('recipe_share_revoked', [
                'recipe_id' => $recipe->getKey(),
                'user_id' => $share->user_id,
                'share_id' => $share->getKey(),
            ]);
        }

        return $share->refresh();
    }

    /**
     * A recipient must be a verified account that may view shared recipes, and never the owner.
     */
    private function canReceive(User $recipient, Recipe $recipe): bool
    {
        return $recipient->hasVerifiedEmail()
            && (int) $recipient->getKey() !== (int) $recipe->author_id
            && $recipient->can(AppPermissions::RECIPES_VIEW_SHARED);
    }

    /**
     * @return array{0: RecipeShare, 1: bool} The grant and whether it is newly active (created or reactivated).
     */
    private function grant(User $owner, Recipe $recipe, User $recipient): array
    {
        // Serialise grants per recipe so concurrent invitations cannot exceed the sharing limit.
        Recipe::query()->whereKey($recipe->getKey())->lockForUpdate()->firstOrFail();

        $existing = RecipeShare::query()
            ->where('recipe_id', $recipe->getKey())
            ->where('user_id', $recipient->getKey())
            ->lockForUpdate()
            ->orderByDesc('id')
            ->get();

        if ($active = $existing->first(fn (RecipeShare $share): bool => $share->revoked_at === null)) {
            return [$active, false];
        }

        $limit = $this->quota->sharingLimit($owner);

        if ($limit !== null && RecipeShare::query()->active()->where('recipe_id', $recipe->getKey())->count() >= $limit) {
            throw ValidationException::withMessages(['email' => __('quota.errors.shares_reached', ['limit' => $limit])]);
        }

        try {
            if ($revoked = $existing->first()) {
                $revoked->forceFill(['revoked_at' => null, 'shared_by' => $owner->getKey(), 'permission' => 'view'])->save();

                return [$revoked, true];
            }

            return [RecipeShare::query()->create([
                'recipe_id' => $recipe->getKey(),
                'user_id' => $recipient->getKey(),
                'permission' => 'view',
                'shared_by' => $owner->getKey(),
            ]), true];
        } catch (UniqueConstraintViolationException) {
            // A concurrent request created the same grant first; treat it as already shared.
            return [RecipeShare::query()->active()->where('recipe_id', $recipe->getKey())->where('user_id', $recipient->getKey())->firstOrFail(), false];
        }
    }
}
