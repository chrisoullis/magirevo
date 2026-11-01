<?php

namespace App\Services;

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\HouseholdMember;
use App\Models\User;
use App\Notifications\HouseholdInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HouseholdInvitationService
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Inviting an address that already has a pending invitation revokes the old link and issues a new one (resend).
     * No account lookup is made, so the response never reveals whether an address is registered.
     */
    public function invite(User $actor, Household $household, string $email, HouseholdRole $role, string $locale): HouseholdInvitation
    {
        Gate::forUser($actor)->authorize('manageInvitations', $household);

        $email = Str::lower(trim($email));
        $limitKey = 'household-invite:'.$actor->getKey();

        // Every attempt counts, valid or not, so the form cannot be used to probe addresses.
        if (RateLimiter::tooManyAttempts($limitKey, config('planner.invitations_per_hour'))) {
            throw ValidationException::withMessages(['email' => __('households.errors.too_many_invitations')]);
        }
        RateLimiter::hit($limitKey, 3600);

        $token = Str::random(48);

        $invitation = DB::transaction(function () use ($actor, $household, $email, $role, $token): HouseholdInvitation {
            Household::query()->whereKey($household->getKey())->lockForUpdate()->firstOrFail();

            $actorRole = HouseholdMember::query()
                ->where('household_id', $household->getKey())
                ->where('user_id', $actor->getKey())
                ->first()?->role ?? abort(404);

            if (! in_array($role, $actorRole->grantableRoles(), true)) {
                throw ValidationException::withMessages(['role' => __('households.errors.role_not_allowed')]);
            }

            $alreadyMember = HouseholdMember::query()
                ->where('household_id', $household->getKey())
                ->whereIn('user_id', User::query()->whereRaw('lower(email) = ?', [$email])->select('id'))
                ->exists();

            if ($alreadyMember) {
                throw ValidationException::withMessages(['email' => __('households.errors.already_member')]);
            }

            $pending = HouseholdInvitation::query()->where('household_id', $household->getKey())->usable();
            $memberCount = $household->members()->count();

            if ($memberCount + (clone $pending)->count() >= config('planner.max_members_per_household')
                && ! (clone $pending)->where('email', $email)->exists()) {
                throw ValidationException::withMessages(['email' => __('households.errors.member_limit')]);
            }

            if ((clone $pending)->count() >= config('planner.max_pending_invitations')
                && ! (clone $pending)->where('email', $email)->exists()) {
                throw ValidationException::withMessages(['email' => __('households.errors.invitation_limit')]);
            }

            HouseholdInvitation::query()
                ->where('household_id', $household->getKey())
                ->where('email', $email)
                ->pending()
                ->update(['revoked_at' => now()]);

            $invitation = HouseholdInvitation::create([
                'household_id' => $household->getKey(),
                'email' => $email,
                'role' => $role,
                'token_hash' => HouseholdInvitation::hashToken($token),
                'invited_by' => $actor->getKey(),
                'expires_at' => now()->addDays(config('planner.invitation_ttl_days')),
            ]);

            $this->audit->record('household_invitation_sent', ['household_id' => $household->getKey(), 'invitation_id' => $invitation->getKey(), 'role' => $role->value]);

            return $invitation;
        });

        Notification::route('mail', $email)->notify(new HouseholdInvitationNotification($invitation, $token, $locale));

        return $invitation;
    }

    public function revoke(User $actor, HouseholdInvitation $invitation): void
    {
        $household = $invitation->household;
        Gate::forUser($actor)->authorize('manageInvitations', $household);

        $invitation->newQuery()->whereKey($invitation->getKey())->pending()->update(['revoked_at' => now()]);
        $this->audit->record('household_invitation_revoked', ['household_id' => $household->getKey(), 'invitation_id' => $invitation->getKey()]);
    }

    /**
     * Returns the invitation only when this user may accept it; otherwise null, so every failure looks the same.
     */
    public function preview(User $user, string $token): ?HouseholdInvitation
    {
        $invitation = HouseholdInvitation::query()
            ->with('household')
            ->where('token_hash', HouseholdInvitation::hashToken($token))
            ->first();

        return $invitation && $this->acceptableBy($invitation, $user) ? $invitation : null;
    }

    public function accept(User $user, string $token): Household
    {
        return DB::transaction(function () use ($user, $token): Household {
            $invitation = HouseholdInvitation::query()
                ->where('token_hash', HouseholdInvitation::hashToken($token))
                ->lockForUpdate()
                ->first();

            if (! $invitation || ! $this->acceptableBy($invitation, $user)) {
                throw ValidationException::withMessages(['token' => __('households.errors.invalid_invitation')]);
            }

            Household::query()->whereKey($invitation->household_id)->lockForUpdate()->firstOrFail();

            if (! $invitation->household->members()->where('user_id', $user->getKey())->exists()) {
                if ($invitation->household->members()->count() >= config('planner.max_members_per_household')) {
                    throw ValidationException::withMessages(['token' => __('households.errors.member_limit')]);
                }

                $invitation->household->members()->create(['user_id' => $user->getKey(), 'role' => $invitation->role]);
            }

            $invitation->update(['accepted_at' => now(), 'accepted_by' => $user->getKey()]);
            $this->audit->record('household_invitation_accepted', ['household_id' => $invitation->household_id, 'invitation_id' => $invitation->getKey()]);

            return $invitation->household;
        });
    }

    private function acceptableBy(HouseholdInvitation $invitation, User $user): bool
    {
        return $invitation->isUsable()
            && $user->hasVerifiedEmail()
            && Gate::forUser($user)->allows('viewAny', Household::class)
            && Str::lower($user->email) === $invitation->email;
    }
}
