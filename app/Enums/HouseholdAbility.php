<?php

namespace App\Enums;

/**
 * What a household role may do inside one household. These are never Spatie permissions:
 * global permissions say whether the planner may be used at all, household roles say what a member may do here.
 */
enum HouseholdAbility: string
{
    case View = 'view';
    case Update = 'update';
    case ManageMembers = 'manage_members';
    case ManageInvitations = 'manage_invitations';
    case EditPlans = 'edit_plans';
    case EditShopping = 'edit_shopping';
    case ConsumeBatches = 'consume_batches';
    case Delete = 'delete';
    case TransferOwnership = 'transfer_ownership';
}
