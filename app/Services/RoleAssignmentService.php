<?php

namespace App\Services;

use App\Models\User;
use App\Support\AppPermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only supported way to change which roles a user has.
 * Elevated roles require users.manage, and nobody can change their own roles or remove the last administrator.
 */
class RoleAssignmentService
{
    public function __construct(private AuditLogger $audit) {}

    public function assign(User $actor, User $target, string $role): void
    {
        $this->authorize($actor, $target, $role);

        DB::transaction(function () use ($actor, $target, $role): void {
            $target->assignRole($role);
            $this->audit->record('role_assigned', ['actor_id' => $actor->getKey(), 'user_id' => $target->getKey(), 'role' => $role]);
        });
    }

    public function revoke(User $actor, User $target, string $role): void
    {
        $this->authorize($actor, $target, $role);

        DB::transaction(function () use ($actor, $target, $role): void {
            if ($role === AppPermissions::ADMIN) {
                $this->assertNotLastAdministrator($target);
            }

            $target->removeRole($role);
            $this->audit->record('role_revoked', ['actor_id' => $actor->getKey(), 'user_id' => $target->getKey(), 'role' => $role]);
        });
    }

    private function authorize(User $actor, User $target, string $role): void
    {
        if (! in_array($role, AppPermissions::ROLES, true)) {
            throw ValidationException::withMessages(['role' => 'Unknown role.']);
        }

        if (! $actor->can(AppPermissions::USERS_MANAGE) || $actor->is($target)) {
            throw new AuthorizationException('You cannot change this user\'s roles.');
        }

        if ($role !== AppPermissions::MEMBER && ! $actor->hasRole(AppPermissions::ADMIN)) {
            throw new AuthorizationException('Only administrators can manage elevated roles.');
        }
    }

    private function assertNotLastAdministrator(User $target): void
    {
        $remaining = User::role(AppPermissions::ADMIN)
            ->whereKeyNot($target->getKey())
            ->lockForUpdate()
            ->count();

        if ($remaining === 0) {
            throw ValidationException::withMessages(['role' => 'The last administrator cannot be removed.']);
        }
    }
}
