<?php

/** Local-only HTTP runner for the isolated cooking release schema. Uses real auth, CSRF and session endpoints. */
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Tests\Support\CookingReleaseEnvironment;

$public = realpath(__DIR__.'/../../public');
$file = realpath($public.parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if ($file && str_starts_with($file, $public.DIRECTORY_SEPARATOR) && is_file($file) && pathinfo($file, PATHINFO_EXTENSION) !== 'php') {
    return false;
}
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
abort_unless(app()->environment('testing'), 403);
CookingReleaseEnvironment::useSchema((string) getenv('COOKING_RELEASE_SCHEMA'));
$app->handleRequest(Request::capture());
