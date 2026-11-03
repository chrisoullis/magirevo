<?php

use App\Models\Recipe;
use App\Models\RecipeRevision;
use App\Models\User;
use App\Services\RecipeRevisionService;
use App\Services\Recipes\RecipeVariationContent;
use App\Services\Recipes\RecipeVariationPublishingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('testing') || ! str_ends_with(DB::connection()->getDatabaseName(), '_test')) {
    exit(2);
}

config(['recipe-variations.enabled' => true, 'recipe-variations.public_submissions_enabled' => true]);
Storage::persistentFake('local');
Storage::persistentFake('public');
DB::statement("SET statement_timeout = '15s'");
DB::statement("SET application_name = 'variation-publication-test-worker'");
[, $action, $actorId, $recipeId, $revisionId, $startAt] = $argv;

try {
    $actor = User::findOrFail($actorId);
    Auth::setUser($actor);
    $recipe = Recipe::findOrFail($recipeId);
    $revision = (int) $revisionId > 0 ? RecipeRevision::findOrFail($revisionId) : null;
    $version = app(RecipeVariationContent::class)->version($recipe);
    while (microtime(true) < (float) $startAt) {
        usleep(500);
    }
    $publishing = app(RecipeVariationPublishingService::class);
    match ($action) {
        'submit' => $publishing->submit($actor, $recipe, $version, [
            'rights_confirmed' => true, 'meaningful_changes_confirmed' => true,
            'summaries' => ['en' => 'Roasted the mushrooms first and developed a different cooking method.', 'el' => 'Έψησα πρώτα τα μανιτάρια και ανέπτυξα διαφορετική μέθοδο μαγειρέματος.'],
        ]),
        'approve' => app(RecipeRevisionService::class)->approve($actor, $revision, 'Independently reviewed and confirmed the cooking changes.', true),
        'withdraw' => $publishing->withdraw($actor, $recipe),
        'decline_rights' => $publishing->reviewRights($actor, $revision, false, 'Independent evidence review requires declining the earlier clearance.', false),
    };
    echo json_encode(['ok' => true])."\n";
} catch (Throwable $exception) {
    echo json_encode(['ok' => false, 'error' => $exception::class, 'message' => mb_substr($exception->getMessage(), 0, 300)])."\n";
}
