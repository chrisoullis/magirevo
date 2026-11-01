<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Recipe;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Support\AppPermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Plan usage limits for personal recipes and shares. Independent of roles and permissions.
 *
 * Counting rule: every non-deleted recipe the user authored that is not public counts (drafts, private,
 * shared, pending submissions, archived and published-but-private). Approved public recipes never count,
 * so a recipe cannot dodge the limit by being briefly made public: leaving public is checked like a creation.
 *
 * Writers serialise per user by locking the users row inside a transaction, so concurrent requests cannot
 * both pass the check. Going over the limit never deletes anything; it only blocks further counted growth.
 */
class RecipeQuotaService
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Editorial staff (reviewers and publishers) create site content, so their recipes are not personal usage.
     */
    public function isExempt(User $user): bool
    {
        return $user->can(AppPermissions::RECIPES_PUBLISH) || $user->can(AppPermissions::RECIPES_REVIEW);
    }

    public function entitlement(User $user): UserEntitlement
    {
        $entitlement = $user->relationLoaded('entitlement') ? $user->entitlement : $user->entitlement()->first();

        if ($entitlement === null) {
            $entitlement = UserEntitlement::query()->firstOrCreate(['user_id' => $user->getKey()], ['plan_id' => Plan::free()->getKey()]);
        }

        return $entitlement->loadMissing('plan');
    }

    /**
     * The effective limit, or null when unlimited.
     */
    public function personalRecipeLimit(User $user): ?int
    {
        if ($this->isExempt($user)) {
            return null;
        }

        $entitlement = $this->entitlement($user);

        return $entitlement->personal_recipe_limit_override ?? $entitlement->plan->max_personal_recipes;
    }

    public function sharingLimit(User $user): ?int
    {
        if ($this->isExempt($user)) {
            return null;
        }

        $entitlement = $this->entitlement($user);

        return $entitlement->sharing_limit_override ?? $entitlement->plan->max_active_shares_per_recipe;
    }

    public function usage(User $user): int
    {
        return Recipe::query()->countedAgainstQuota()->where('author_id', $user->getKey())->count();
    }

    public function hasReachedLimit(User $user): bool
    {
        $limit = $this->personalRecipeLimit($user);

        return $limit !== null && $this->usage($user) >= $limit;
    }

    /**
     * Runs the callback only if the owner can take one more counted recipe, holding a lock until it commits.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function withinPersonalQuota(User $owner, callable $callback): mixed
    {
        return DB::transaction(function () use ($owner, $callback): mixed {
            User::query()->whereKey($owner->getKey())->lockForUpdate()->firstOrFail();

            $limit = $this->personalRecipeLimit($owner);

            if ($limit !== null && $this->usage($owner) >= $limit) {
                $this->audit->record('recipe_quota_blocked', ['user_id' => $owner->getKey(), 'limit' => $limit]);

                throw ValidationException::withMessages(['quota' => __('quota.errors.recipes_reached', ['limit' => $limit])]);
            }

            return $callback();
        });
    }

    /**
     * Guards a workflow change that may move a recipe into counted usage. Only the owner's own actions are
     * limited: an editor taking a recipe down must never be blocked by the author's quota.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function guardTransition(Recipe $recipe, bool $increasesUsage, callable $callback): mixed
    {
        $owner = $recipe->author;

        if (! $increasesUsage || $owner === null || (int) Auth::id() !== (int) $owner->getKey()) {
            return $callback();
        }

        return $this->withinPersonalQuota($owner, $callback);
    }

    /**
     * @return array{used: int, limit: ?int, reached: bool}
     */
    public function summary(User $user): array
    {
        $limit = $this->personalRecipeLimit($user);
        $used = $this->usage($user);

        return ['used' => $used, 'limit' => $limit, 'reached' => $limit !== null && $used >= $limit];
    }

    public function usageLabel(User $user): ?string
    {
        $summary = $this->summary($user);

        return $summary['limit'] === null
            ? null
            : __('quota.usage', ['used' => $summary['used'], 'limit' => $summary['limit']]);
    }

    /**
     * Admin-only. A null override means "use the plan"; plans express unlimited with a null maximum.
     */
    public function updateEntitlement(User $admin, UserEntitlement $entitlement, int $planId, ?int $recipeOverride, ?int $sharingOverride): UserEntitlement
    {
        if (! Gate::forUser($admin)->allows('update', $entitlement)) {
            throw new AuthorizationException;
        }

        foreach (['personal_recipe_limit_override' => $recipeOverride, 'sharing_limit_override' => $sharingOverride] as $field => $value) {
            if ($value !== null && $value < 0) {
                throw ValidationException::withMessages([$field => __('quota.errors.negative')]);
            }
        }

        $entitlement->forceFill([
            'plan_id' => $planId,
            'personal_recipe_limit_override' => $recipeOverride,
            'sharing_limit_override' => $sharingOverride,
            'granted_by' => $admin->getKey(),
        ])->save();

        $this->audit->record('entitlement_changed', [
            'user_id' => $entitlement->user_id,
            'plan_id' => $planId,
            'recipe_override' => $recipeOverride,
            'sharing_override' => $sharingOverride,
        ]);

        return $entitlement;
    }
}
