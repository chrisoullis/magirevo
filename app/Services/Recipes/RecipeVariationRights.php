<?php

namespace App\Services\Recipes;

use App\Models\Recipe;
use App\Models\RecipeReusePermission;

class RecipeVariationRights
{
    public function current(Recipe $source): ?RecipeReusePermission
    {
        // A later restrictive or pending decision must not fall back to an older permissive grant.
        return $source->reusePermissions()->orderByDesc('id')->first();
    }

    /** Known license identifiers cannot be contradicted by permissive preference flags. */
    public function licenseCompatible(RecipeReusePermission $permission, bool $public = false): bool
    {
        $identifier = strtoupper(trim((string) $permission->license_identifier));
        $url = (string) $permission->license_url;
        $parts = parse_url($url);
        if (is_array($parts) && in_array(strtolower($parts['host'] ?? ''), ['creativecommons.org', 'www.creativecommons.org'], true)) {
            $path = rtrim($parts['path'] ?? '', '/');
            if (preg_match('~^/licenses/([a-z-]+)/([0-9.]+)(?:/(?:deed|legalcode)(?:\.[a-z_-]+)?)?$~i', $path, $match)) {
                $fromUrl = 'CC-'.strtoupper($match[1]).'-'.$match[2];
            } elseif ($path === '/publicdomain/zero/1.0') {
                $fromUrl = 'CC0-1.0';
            } else {
                return false;
            }
            if ($identifier !== '' && $identifier !== $fromUrl) {
                return false;
            }
            $identifier = $fromUrl;
        }
        if (! str_starts_with($identifier, 'CC')) {
            return true;
        }
        if ($identifier === 'CC0-1.0') {
            return true;
        }
        if (! preg_match('/^CC-BY(?:-NC)?(?:-SA|-ND)?-(3\.0|4\.0)$/', $identifier)) {
            return false;
        }
        if (str_contains($identifier, '-ND-') || (str_contains($identifier, '-NC-') && config('recipe-variations.commercial_context', true))) {
            return false;
        }
        if ($public && (blank($url) || ! $permission->requires_attribution || ! $permission->allows_public_attribution)) {
            return false;
        }
        if ($public && str_contains($identifier, '-SA-') && (! $permission->requires_share_alike || blank($url))) {
            return false;
        }

        return true;
    }

    public function permitsPrivate(Recipe $source): bool
    {
        $permission = $this->current($source);

        return $permission !== null
            && (int) $permission->recipe_revision_id === (int) $source->current_revision_id
            && $this->licenseCompatible($permission)
            && $permission->verification_status === 'verified'
            && $permission->suspended_at === null
            && in_array($permission->mode, ['personal', 'personal_and_public_submission'], true)
            && $permission->allows_text_reproduction
            && $permission->allows_private_adaptation
            && ! $permission->prohibits_derivatives
            && (! config('recipe-variations.commercial_context', true) || $permission->allows_commercial_use);
    }
}
