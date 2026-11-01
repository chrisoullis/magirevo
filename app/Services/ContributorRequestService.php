<?php

namespace App\Services;

use App\Enums\ContributorRequestStatus;
use App\Models\ContributorRequest;
use App\Models\User;
use App\Notifications\ContributorRequestApproved;
use App\Notifications\ContributorRequestRejected;
use App\Notifications\ContributorRequestSubmitted;
use App\Support\AppPermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * The only place that changes contributor applications and the contributor role together.
 * Status and role always change in one transaction, and approve/reject are safe to repeat.
 */
class ContributorRequestService
{
    private const MAX_SUBMISSIONS_PER_DAY = 3;

    public function __construct(private AuditLogger $audit) {}

    public function submit(User $user, ?string $message, string $locale): ContributorRequest
    {
        if ($user->hasRole(AppPermissions::CONTRIBUTOR) || $user->can(AppPermissions::RECIPES_CREATE)) {
            throw ValidationException::withMessages(['message' => __('contributor.already_contributor')]);
        }

        if (! Gate::forUser($user)->allows('create', ContributorRequest::class)) {
            throw new AuthorizationException(__('contributor.not_allowed'));
        }

        $key = 'contributor-request:'.$user->getKey();

        if (RateLimiter::tooManyAttempts($key, self::MAX_SUBMISSIONS_PER_DAY)) {
            throw ValidationException::withMessages(['message' => __('contributor.too_many')]);
        }

        $message = filled($message) ? trim($message) : null;

        if ($message !== null && mb_strlen($message) > 1000) {
            throw ValidationException::withMessages(['message' => 'Please keep your message under 1000 characters.']);
        }

        try {
            $request = DB::transaction(function () use ($user, $message, $locale): ContributorRequest {
                $request = ContributorRequest::query()->create([
                    'user_id' => $user->getKey(),
                    'message' => $message,
                    'locale' => in_array($locale, config('translatable.locales'), true) ? $locale : 'en',
                ]);

                $this->audit->record('contributor_request_submitted', ['user_id' => $user->getKey(), 'request_id' => $request->getKey()]);

                return $request;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['message' => __('contributor.already_pending')]);
        }

        RateLimiter::hit($key, 86400);

        Notification::send(
            User::permission(AppPermissions::CONTRIBUTORS_REVIEW)->get()->filter->hasVerifiedEmail(),
            new ContributorRequestSubmitted($request->load('user')),
        );

        return $request;
    }

    public function cancel(User $user, ContributorRequest $request): ContributorRequest
    {
        Gate::forUser($user)->authorize('cancel', $request);

        return $this->decide($request, $user, ContributorRequestStatus::Cancelled, null, 'contributor_request_cancelled');
    }

    public function approve(User $actor, ContributorRequest $request): ContributorRequest
    {
        Gate::forUser($actor)->authorize('approve', $request);

        $request = $this->decide($request, $actor, ContributorRequestStatus::Approved, null, 'contributor_request_approved', function (ContributorRequest $locked): void {
            $locked->user->assignRole(AppPermissions::CONTRIBUTOR);
        });

        return $request;
    }

    public function reject(User $actor, ContributorRequest $request, string $reason): ContributorRequest
    {
        Gate::forUser($actor)->authorize('reject', $request);

        if (blank($reason)) {
            throw ValidationException::withMessages(['rejection_reason' => 'A rejection reason is required.']);
        }

        return $this->decide($request, $actor, ContributorRequestStatus::Rejected, trim($reason), 'contributor_request_rejected');
    }

    /**
     * Removes contributor access. Existing personal recipes are never touched: owners keep reading them.
     */
    public function revokeContributor(User $actor, User $user): void
    {
        if ($actor->is($user) || ! $actor->hasVerifiedEmail() || ! $actor->can(AppPermissions::CONTRIBUTORS_REVIEW)) {
            throw new AuthorizationException('You cannot revoke this user\'s contributor access.');
        }

        DB::transaction(function () use ($actor, $user): void {
            if (! $user->hasRole(AppPermissions::CONTRIBUTOR)) {
                return;
            }

            $user->removeRole(AppPermissions::CONTRIBUTOR);
            $this->audit->record('contributor_revoked', ['actor_id' => $actor->getKey(), 'user_id' => $user->getKey()]);
        });
    }

    /**
     * @param  (callable(ContributorRequest): void)|null  $within
     */
    private function decide(ContributorRequest $request, User $actor, ContributorRequestStatus $to, ?string $reason, string $event, ?callable $within = null): ContributorRequest
    {
        $notify = false;

        $request = DB::transaction(function () use ($request, $actor, $to, $reason, $event, $within, &$notify): ContributorRequest {
            $locked = ContributorRequest::query()->with('user')->lockForUpdate()->findOrFail($request->getKey());

            if ($locked->status === $to) {
                return $locked;
            }

            if (! $locked->isPending()) {
                throw ValidationException::withMessages(['status' => "This application was already {$locked->status->value}."]);
            }

            $locked->forceFill([
                'status' => $to,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            $within?->__invoke($locked);

            $this->audit->record($event, ['actor_id' => $actor->getKey(), 'user_id' => $locked->user_id, 'request_id' => $locked->getKey()]);

            $notify = in_array($to, [ContributorRequestStatus::Approved, ContributorRequestStatus::Rejected], true);

            return $locked;
        });

        if ($notify) {
            $notification = $to === ContributorRequestStatus::Approved
                ? new ContributorRequestApproved($request, $request->locale)
                : new ContributorRequestRejected($request, $request->locale);

            $request->user->notify($notification);
        }

        return $request;
    }
}
