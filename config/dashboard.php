<?php

return [
    // Google Analytics 4 Data API property. Leave empty until a connector is installed and credentials are set.
    'ga4' => [
        'property_id' => env('GA4_PROPERTY_ID'),
    ],

    'storage_paths' => [
        'public' => storage_path('app/public'),
        'private' => storage_path('app/private'),
    ],

    'log_tail_bytes' => 524288,
];
