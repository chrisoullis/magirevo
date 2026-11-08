<?php

return [
    // Set either flag to false to disable that part of the cooking experience.
    'sessions_enabled' => env('COOKING_SESSIONS_ENABLED', true),
    'step_groups_enabled' => env('COOKING_STEP_GROUPS_ENABLED', true),
];
