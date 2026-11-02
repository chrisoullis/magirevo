<?php

namespace App\Console\Commands;

use App\Services\AuditLogger;
use App\Services\RecipeContentGuard;
use Illuminate\Console\Command;

class RecipeContentGuardCommand extends Command
{
    protected $signature = 'recipes:content-guard {mode? : off, monitor or enforce}';

    protected $description = 'Show or change how the database guards published recipe content (off, monitor or enforce)';

    public function handle(RecipeContentGuard $guard, AuditLogger $audit): int
    {
        $mode = $this->argument('mode');

        if ($mode === null) {
            $this->info('Content guard mode: '.$guard->mode());

            return self::SUCCESS;
        }

        if (! in_array($mode, RecipeContentGuard::MODES, true)) {
            $this->error('Mode must be one of: '.implode(', ', RecipeContentGuard::MODES).'.');

            return self::INVALID;
        }

        $previous = $guard->mode();
        $guard->setMode($mode);
        $audit->record('recipe_content_guard_mode_changed', ['from' => $previous, 'to' => $mode]);
        $this->info("Content guard mode changed from {$previous} to {$mode}.");

        return self::SUCCESS;
    }
}
