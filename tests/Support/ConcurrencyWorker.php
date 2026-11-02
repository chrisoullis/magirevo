<?php

/**
 * Runs one recipe-revision action in its own PHP process, and so its own PostgreSQL connection, for the concurrency
 * tests. Usage: php ConcurrencyWorker.php <action> <userId> <subjectId> <startAtMicrotime> [reason]
 * Prints a single JSON line describing the outcome.
 */

use App\Models\Recipe;
use App\Models\RecipeRevision;
use App\Models\User;
use App\Services\RecipeRevisionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $action, $userId, $subjectId, $startAt] = $argv;
$reason = $argv[5] ?? 'concurrency test';

if (! str_ends_with((string) DB::connection()->getDatabaseName(), '_test')) {
    fwrite(STDOUT, json_encode(['ok' => false, 'error' => 'refused: not a test database'])."\n");
    exit(2);
}

// Barrier so both processes reach the contested call at nearly the same moment.
while (microtime(true) < (float) $startAt) {
    usleep(500);
}

try {
    $user = User::findOrFail((int) $userId);
    Auth::setUser($user);
    $service = $app->make(RecipeRevisionService::class);

    $result = match ($action) {
        'create_draft' => $service->createDraft($user, Recipe::findOrFail((int) $subjectId))->getKey(),
        'submit' => $service->submit($user, RecipeRevision::findOrFail((int) $subjectId))->getKey(),
        'approve' => $service->approve($user, RecipeRevision::findOrFail((int) $subjectId))->getKey(),
        'rollback' => $service->rollback($user, RecipeRevision::findOrFail((int) $subjectId), $reason)->getKey(),
    };

    fwrite(STDOUT, json_encode(['ok' => true, 'revision' => $result])."\n");
} catch (Throwable $exception) {
    fwrite(STDOUT, json_encode(['ok' => false, 'error' => $exception::class, 'message' => mb_substr($exception->getMessage(), 0, 200)])."\n");
}
