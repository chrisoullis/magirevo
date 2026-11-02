<?php

namespace App\Console\Commands;

use App\Services\DietaryClaimService;
use Illuminate\Console\Command;

class RevalidateDietaryClaims extends Command
{
    protected $signature = 'dietary:revalidate';

    protected $description = 'Invalidate approved dietary claims whose recipe content or rule version has changed. Run after changing config/dietary.php.';

    public function handle(DietaryClaimService $claims): int
    {
        $this->info("Invalidated {$claims->revalidateAll()} claim(s).");

        return self::SUCCESS;
    }
}
