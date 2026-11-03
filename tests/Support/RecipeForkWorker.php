<?php

use App\Models\Recipe;
use App\Models\User;
use App\Services\Recipes\RecipeForkService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! str_ends_with(DB::connection()->getDatabaseName(), '_test')) {
    exit(2);
}
config(['recipe-variations.enabled' => true]);
[, $actorId, $sourceId, $key, $startAt] = $argv;
while (microtime(true) < (float) $startAt) {
    usleep(500);
}
try {
    $recipe = app(RecipeForkService::class)->fork(User::findOrFail($actorId), Recipe::findOrFail($sourceId), ['idempotency_key' => $key]);
    echo json_encode(['ok' => true, 'id' => $recipe->id])."\n";
} catch (Throwable $error) {
    echo json_encode(['ok' => false, 'error' => $error::class])."\n";
}
