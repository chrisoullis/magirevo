<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Lookup data (categories, tags, difficulties, ingredients, units, scaling modes):
 * staff can view and select records, only administrators can change them.
 */
abstract class LookupPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user, Model $record): bool
    {
        return $user->isStaff();
    }

    public function create(User $user): bool
    {
        return $user->isAdministrator();
    }

    public function update(User $user, Model $record): bool
    {
        return $user->isAdministrator();
    }

    public function delete(User $user, Model $record): bool
    {
        return $user->isAdministrator();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdministrator();
    }
}
