<?php

namespace App\Console\Commands;

use Database\Seeders\CategorySeeder;
use Illuminate\Console\Command;

class RestoreCategoryDefaults extends Command
{
    protected $signature = 'categories:restore-defaults';

    protected $description = 'Development only: reset the seeded categories (parents, names, slugs, order, active flags) to the default taxonomy';

    public function handle(CategorySeeder $seeder): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->components->error('This command overwrites administrator changes and is only available in local or testing environments.');

            return self::FAILURE;
        }

        if (! $this->option('no-interaction') && ! $this->components->confirm('Overwrite the seeded categories with the taxonomy defaults? Recipe assignments are kept.')) {
            return self::FAILURE;
        }

        $seeder->restoreDefaults();

        $this->components->info('Seeded categories restored to the default taxonomy.');

        return self::SUCCESS;
    }
}
