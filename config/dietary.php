<?php

return [
    /*
    | Claim definitions per tag code. Bumping rule_version invalidates every approval made under the
    | previous version (run `php artisan dietary:revalidate` after deploying the change).
    */
    'default_rule_version' => 1,

    'public_release_enabled' => env('DIETARY_CLAIMS_PUBLIC', false),
    'public_claim_codes' => [],

    'claims' => [
        'vegetarian' => ['rule_version' => 1],
        'vegan' => ['rule_version' => 1],
        'gluten-free' => ['rule_version' => 1, 'high_risk' => true],
    ],

    'nutrition_analysis' => [
        'enabled' => false,
        'criteria_version' => 1,
        'data_source' => null,
        'definitions' => [],
    ],

    /* Claims in these classifications need qualified evidence and the dedicated high-risk permission. */
    'high_risk_classifications' => ['nutrition_claim', 'ingredient_allergen'],

    'qualified_verification_methods' => ['supplier_documentation', 'laboratory_test', 'qualified_expert_review'],

    /* Bump when the fingerprint inputs change; it invalidates all approvals by design. */
    'fingerprint_version' => 2,

    /* Optional reminders only: null disables them. Approvals never expire by date. */
    'review_reminder_months' => null,
];
