<?php

/**
 * Planner limits. They are a single seam for future subscription plans: a plan-aware resolver can replace
 * these values without touching the services that read them.
 */
return [
    'max_households_per_user' => (int) env('PLANNER_MAX_HOUSEHOLDS_PER_USER', 5),
    'max_members_per_household' => (int) env('PLANNER_MAX_MEMBERS_PER_HOUSEHOLD', 12),
    'max_pending_invitations' => (int) env('PLANNER_MAX_PENDING_INVITATIONS', 20),
    'invitation_ttl_days' => (int) env('PLANNER_INVITATION_TTL_DAYS', 7),
    'max_entries_per_plan' => (int) env('PLANNER_MAX_ENTRIES_PER_PLAN', 150),
    'max_shopping_items_per_list' => (int) env('PLANNER_MAX_SHOPPING_ITEMS_PER_LIST', 300),
    'max_templates_per_scope' => (int) env('PLANNER_MAX_TEMPLATES_PER_SCOPE', 25),
    'max_batches_per_scope' => (int) env('PLANNER_MAX_BATCHES_PER_SCOPE', 60),
    'invitations_per_hour' => (int) env('PLANNER_INVITATIONS_PER_HOUR', 20),
];
