<?php

namespace Database\Seeders\Concerns;

use App\Models\Recipe;
use App\Services\RecipeContentGuard;

/**
 * Sample recipe seeders are for development databases. They never write to production data, never run while the
 * published-content guard is enforcing, and never rewrite a recipe that is live; changing live content goes through
 * the revision workflow instead.
 */
trait ProtectsPublishedRecipes
{
    protected function sampleRecipesAllowed(): bool
    {
        if (app()->isProduction()) {
            $this->command?->warn(static::class.' skipped: sample recipes are not seeded in production.');

            return false;
        }

        if (app(RecipeContentGuard::class)->mode() === 'enforce') {
            $this->command?->warn(static::class.' skipped: published recipe content is protected (guard is enforcing).');

            return false;
        }

        return true;
    }

    /**
     * Live means published and under revision control; a bare sample recipe seeded earlier has no revision yet.
     */
    protected function isLive(Recipe $recipe): bool
    {
        return $recipe->current_revision_id !== null && $this->plain($recipe->status) === 'published' && $this->plain($recipe->visibility) === 'public' && ! $recipe->trashed();
    }

    private function plain(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : $value;
    }
}
