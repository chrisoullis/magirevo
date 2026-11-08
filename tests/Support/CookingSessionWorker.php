<?php

use App\Models\Recipe;
use App\Models\User;
use App\Services\CookingSessionService;
use App\Services\RecipePublishingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\CookingReleaseEnvironment;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[, $schema, $encoded, $startAt] = $argv;
CookingReleaseEnvironment::useSchema($schema);
$job = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
while (microtime(true) < (float) $startAt) {
    usleep(500);
}
try {
    $actor = User::findOrFail($job['user']);
    Auth::setUser($actor);
    $service = app(CookingSessionService::class);
    $session = match ($job['action']) {
        'start' => $service->start($actor, $job['recipe'], $job['key']),
        'update' => $service->update($actor, $job['session'], $job['version'], $job['event']),
        'publish' => app(RecipePublishingService::class)->publish(Recipe::findOrFail($job['recipe'])),
        'withdraw' => app(RecipePublishingService::class)->withdraw($actor, Recipe::findOrFail($job['recipe'])),
    };
    echo json_encode(['ok' => true, 'id' => $session?->getKey(), 'version' => $session?->version])."\n";
} catch (Throwable $error) {
    echo json_encode(['ok' => false, 'status' => $error instanceof HttpExceptionInterface ? $error->getStatusCode() : ($error instanceof ValidationException ? 422 : 500),
        'error' => $error::class, 'message' => $error->getMessage()])."\n";
}
