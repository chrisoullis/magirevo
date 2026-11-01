<?php

namespace App\Services;

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class HouseholdService
{
    public function __construct(private HouseholdAccess $access, private AuditLogger $audit) {}

    public function create(User $actor, string $name): Household
    {
        Gate::forUser($actor)->authorize('create', Household::class);

        return DB::transaction(function () use ($actor, $name): Household {
            // Serialise per user so the household limit cannot be bypassed by concurrent requests.
            User::query()->whereKey($actor->getKey())->lockForUpdate()->first();

            if ($actor->households()->count() >= config('planner.max_households_per_user')) {
                throw ValidationException::withMessages(['name' => __('households.errors.household_limit')]);
            }

            $household = Household::create(['name' => trim($name)]);
            $household->members()->create(['user_id' => $actor->getKey(), 'role' => HouseholdRole::Owner]);
            $this->audit->record('household_created', ['household_id' => $household->getKey()]);

            return $household;
        });
    }

    public function rename(User $actor, Household $household, string $name): Household
    {
        Gate::forUser($actor)->authorize('update', $household);
        $household->update(['name' => trim($name)]);

        return $household;
    }

    public function changeRole(User $actor, Household $household, User $target, HouseholdRole $role): void
    {
        DB::transaction(function () use ($actor, $household, $target, $role): void {
            $this->lock($household);
            Gate::forUser($actor)->authorize('manageMembers', $household);

            $actorRole = $this->roleOrFail($actor, $household);
            $member = $this->memberOrFail($target, $household);

            if ($actor->is($target) || ! $actorRole->canManage($member->role) || ! in_array($role, $actorRole->grantableRoles(), true)) {
                throw ValidationException::withMessages(['role' => __('households.errors.role_not_allowed')]);
            }

            $member->update(['role' => $role]);
            $this->audit->record('household_role_changed', ['household_id' => $household->getKey(), 'user_id' => $target->getKey(), 'role' => $role->value]);
        });
    }

    public function removeMember(User $actor, Household $household, User $target): void
    {
        DB::transaction(function () use ($actor, $household, $target): void {
            $this->lock($household);
            Gate::forUser($actor)->authorize('manageMembers', $household);

            $actorRole = $this->roleOrFail($actor, $household);
            $member = $this->memberOrFail($target, $household);

            if ($actor->is($target) || ! $actorRole->canManage($member->role)) {
                throw ValidationException::withMessages(['member' => __('households.errors.role_not_allowed')]);
            }

            $member->delete();
            $this->audit->record('household_member_removed', ['household_id' => $household->getKey(), 'user_id' => $target->getKey()]);
        });
    }

    public function leave(User $actor, Household $household): void
    {
        DB::transaction(function () use ($actor, $household): void {
            $this->lock($household);
            Gate::forUser($actor)->authorize('leave', $household);

            $this->memberOrFail($actor, $household)->delete();
            $this->audit->record('household_left', ['household_id' => $household->getKey()]);
        });
    }

    /**
     * The owner is demoted before the target is promoted, so the one-owner index is never violated.
     */
    public function transferOwnership(User $actor, Household $household, User $target): void
    {
        DB::transaction(function () use ($actor, $household, $target): void {
            $this->lock($household);
            Gate::forUser($actor)->authorize('transferOwnership', $household);

            $current = $this->memberOrFail($actor, $household);
            $next = $this->memberOrFail($target, $household);

            if ($actor->is($target)) {
                throw ValidationException::withMessages(['member' => __('households.errors.role_not_allowed')]);
            }

            $current->update(['role' => HouseholdRole::Admin]);
            $next->update(['role' => HouseholdRole::Owner]);
            $this->audit->record('household_ownership_transferred', ['household_id' => $household->getKey(), 'to_user_id' => $target->getKey()]);
        });
    }

    public function delete(User $actor, Household $household): void
    {
        DB::transaction(function () use ($actor, $household): void {
            $this->lock($household);
            Gate::forUser($actor)->authorize('delete', $household);

            $household->delete();
            $this->audit->record('household_deleted', ['household_id' => $household->getKey()]);
        });
    }

    private function lock(Household $household): void
    {
        Household::query()->whereKey($household->getKey())->lockForUpdate()->firstOrFail();
    }

    private function roleOrFail(User $user, Household $household): HouseholdRole
    {
        return $this->access->roleOf($user, $household) ?? abort(404);
    }

    private function memberOrFail(User $user, Household $household): HouseholdMember
    {
        return HouseholdMember::query()
            ->where('household_id', $household->getKey())
            ->where('user_id', $user->getKey())
            ->lockForUpdate()
            ->first() ?? throw ValidationException::withMessages(['member' => __('households.errors.not_a_member')]);
    }
}
