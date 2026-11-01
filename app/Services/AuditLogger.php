<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Writes security-relevant events to the application log. Only identifiers are recorded, never recipe content.
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function record(string $event, array $context = []): void
    {
        Log::info("audit.{$event}", ['actor_id' => Auth::id(), ...$context]);
    }
}
