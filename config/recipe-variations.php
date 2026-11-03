<?php

return [
    // Remains disabled until the service, editor and release validation stages are complete.
    'enabled' => env('RECIPE_VARIATIONS_ENABLED', false),
    // Rights reviews must explicitly authorize commercial hosting unless configured otherwise.
    'commercial_context' => true,
    'public_submissions_enabled' => false,
    'evidence_disk' => 'recipe_rights',
];
