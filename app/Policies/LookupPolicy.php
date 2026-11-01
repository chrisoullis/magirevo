<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Lookup data (categories, tags, difficulties, ingredients, units, scaling modes).
 * Decisions come from the Shield permissions "{Action}:{Model}", which the seeder grants
 * read access to editors and full access to administrators.
 */
abstract class LookupPolicy
{
    /**
     * @return class-string<Model>
     */
    abstract protected function model(): string;

    private function allows(User $user, string $action): bool
    {
        return $user->hasVerifiedEmail() && $user->can($action.':'.class_basename($this->model()));
    }

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'ViewAny');
    }

    public function view(User $user, Model $record): bool
    {
        return $this->allows($user, 'View');
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'Create');
    }

    public function update(User $user, Model $record): bool
    {
        return $this->allows($user, 'Update');
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->allows($user, 'Delete');
    }

    public function deleteAny(User $user): bool
    {
        return $this->allows($user, 'DeleteAny');
    }
}
