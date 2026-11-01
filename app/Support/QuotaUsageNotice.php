<?php

namespace App\Support;

use App\Models\User;
use App\Services\RecipeQuotaService;

/**
 * The "8 of 20 personal recipes used." line, plus an upgrade-information placeholder once the limit is reached.
 */
class QuotaUsageNotice
{
    public function __construct(private RecipeQuotaService $quota) {}

    public function for(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $label = $this->quota->usageLabel($user);

        if ($label === null) {
            return null;
        }

        return $this->quota->summary($user)['reached'] ? $label.' '.__('quota.upgrade_info') : $label;
    }
}
