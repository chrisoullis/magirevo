<?php

namespace App\Support;

/**
 * Single source of truth for role names, permission keys and the default role-permission mapping.
 *
 * Resource permissions follow Filament Shield's "Action:Subject" naming. Application-level permissions
 * use stable dotted keys. Permissions only answer "may this kind of user do this kind of thing";
 * record-level decisions (ownership, visibility, workflow) stay in the policies.
 */
final class AppPermissions
{
    public const ADMIN = 'admin';

    public const EDITOR = 'editor';

    public const CONTRIBUTOR = 'contributor';

    public const MEMBER = 'member';

    /** @var list<string> */
    public const ROLES = [self::ADMIN, self::EDITOR, self::CONTRIBUTOR, self::MEMBER];

    public const RECIPES_FORK = 'recipes.fork';

    public const VARIATIONS_SUBMIT = 'recipes.variations.submit';

    public const VARIATIONS_REVIEW = 'recipes.variations.review';

    public const VARIATIONS_MANAGE_RIGHTS = 'recipes.variations.manage_rights';

    public const VARIATION_PERMISSIONS = [
        self::RECIPES_FORK,
        self::VARIATIONS_SUBMIT,
        self::VARIATIONS_REVIEW,
        self::VARIATIONS_MANAGE_RIGHTS,
    ];

    public const RECIPES_CREATE = 'recipes.create';

    public const RECIPES_VIEW_OWNED = 'recipes.view_owned';

    public const RECIPES_UPDATE_OWNED = 'recipes.update_owned';

    public const RECIPES_DELETE_OWNED = 'recipes.delete_owned';

    public const RECIPES_SUBMIT = 'recipes.submit';

    public const RECIPES_REVIEW = 'recipes.review';

    public const RECIPES_PUBLISH = 'recipes.publish';

    public const RECIPES_SHARE = 'recipes.share';

    public const RECIPES_VIEW_SHARED = 'recipes.view_shared';

    public const RECIPES_MODERATE_PRIVATE = 'recipes.moderate_private';

    public const RECIPES_PERSONALIZE = 'recipes.personalize';

    public const RECIPES_RATE = 'recipes.rate';

    public const CONTRIBUTORS_REQUEST = 'contributors.request';

    public const CONTRIBUTORS_REVIEW = 'contributors.review';

    public const QUOTAS_MANAGE = 'quotas.manage';

    public const USERS_MANAGE = 'users.manage';

    public const ROLES_MANAGE = 'roles.manage';

    /** Global switch for the consumer planner; what a person may do inside a household is a household role. */
    public const PLANNER_USE = 'planner.use';

    public const HOUSEHOLDS_CREATE = 'households.create';

    public const DIETARY_CLAIMS_VIEW = 'dietary_claims.view';

    public const DIETARY_CLAIMS_SUGGEST = 'dietary_claims.suggest';

    public const DIETARY_CLAIMS_FLAG = 'dietary_claims.flag';

    /** Approve or reject claims. Held by administrators only at first; qualified reviewers can be granted it later. */
    public const DIETARY_CLAIMS_REVIEW = 'dietary_claims.review';

    /** Additionally required for gluten-free, allergen and nutrition claims. */
    public const DIETARY_CLAIMS_REVIEW_HIGH_RISK = 'dietary_claims.review_high_risk';

    public const DIETARY_CLAIMS_SUSPEND = 'dietary_claims.suspend';

    public const DIETARY_CLAIMS_VIEW_AUDIT = 'dietary_claims.view_audit';

    public const REVISIONS_VIEW_HISTORY = 'recipes.revisions.view_history';

    /** Restores an earlier published revision as a new revision. Administrators only at first. */
    public const REVISIONS_ROLLBACK = 'recipes.revisions.rollback';

    /** @var list<string> */
    public const REVISION_PERMISSIONS = [
        self::REVISIONS_VIEW_HISTORY,
        self::REVISIONS_ROLLBACK,
    ];

    /** @var list<string> */
    public const DIETARY_CLAIM_PERMISSIONS = [
        self::DIETARY_CLAIMS_VIEW,
        self::DIETARY_CLAIMS_SUGGEST,
        self::DIETARY_CLAIMS_FLAG,
        self::DIETARY_CLAIMS_REVIEW,
        self::DIETARY_CLAIMS_REVIEW_HIGH_RISK,
        self::DIETARY_CLAIMS_SUSPEND,
        self::DIETARY_CLAIMS_VIEW_AUDIT,
    ];

    /** @var list<string> */
    public const LOOKUP_SUBJECTS = ['Category', 'Tag', 'Ingredient', 'IngredientCatalogGroup', 'Unit', 'Difficulty', 'ScalingMode'];

    /** @var list<string> */
    public const RESOURCE_ACTIONS = ['ViewAny', 'View', 'Create', 'Update', 'Delete', 'DeleteAny', 'Restore', 'RestoreAny', 'ForceDelete', 'ForceDeleteAny', 'Replicate', 'Reorder'];

    /** @var list<string> */
    public const ROLE_ACTIONS = ['ViewAny', 'View', 'Create', 'Update', 'Delete'];

    /**
     * Permissions that the admin role must never lose, so the permission interface cannot lock everyone out.
     *
     * @var list<string>
     */
    public const ADMIN_PROTECTED = [
        self::ROLES_MANAGE,
        self::USERS_MANAGE,
        'ViewAny:Role',
        'View:Role',
        'Create:Role',
        'Update:Role',
        'Delete:Role',
    ];

    /**
     * @return array<string, string>
     */
    public static function customLabels(): array
    {
        return [
            self::RECIPES_FORK => 'Create permitted private recipe variations',
            self::VARIATIONS_SUBMIT => 'Submit recipe variations for public review',
            self::VARIATIONS_REVIEW => 'Review recipe variations',
            self::VARIATIONS_MANAGE_RIGHTS => 'Manage variation rights and private evidence',
            self::RECIPES_CREATE => 'Create personal recipes',
            self::RECIPES_VIEW_OWNED => 'View own recipes',
            self::RECIPES_UPDATE_OWNED => 'Edit own recipes',
            self::RECIPES_DELETE_OWNED => 'Delete own recipes',
            self::RECIPES_SUBMIT => 'Submit recipes for public review',
            self::RECIPES_REVIEW => 'Review submitted recipes',
            self::RECIPES_PUBLISH => 'Publish recipes without review',
            self::RECIPES_SHARE => 'Share own recipes with users',
            self::RECIPES_VIEW_SHARED => 'View recipes shared with me',
            self::RECIPES_MODERATE_PRIVATE => 'Moderate private recipes (audited)',
            self::RECIPES_PERSONALIZE => 'Manage private recipe notes and ingredient changes',
            self::RECIPES_RATE => 'Rate public recipes',
            self::CONTRIBUTORS_REQUEST => 'Request contributor access',
            self::CONTRIBUTORS_REVIEW => 'Review contributor requests',
            self::QUOTAS_MANAGE => 'Manage plans and quotas',
            self::USERS_MANAGE => 'Manage users and role assignments',
            self::ROLES_MANAGE => 'Manage roles and permissions',
            self::PLANNER_USE => 'Use meal planner and shopping lists',
            self::HOUSEHOLDS_CREATE => 'Create households',
            self::REVISIONS_VIEW_HISTORY => 'View recipe revision history and comparisons',
            self::REVISIONS_ROLLBACK => 'Roll back a recipe to an earlier published revision',
            self::DIETARY_CLAIMS_VIEW => 'View dietary claims',
            self::DIETARY_CLAIMS_SUGGEST => 'Suggest dietary claims',
            self::DIETARY_CLAIMS_FLAG => 'Flag problems with dietary claims',
            self::DIETARY_CLAIMS_REVIEW => 'Approve or reject dietary claims',
            self::DIETARY_CLAIMS_REVIEW_HIGH_RISK => 'Approve or reject gluten-free, allergen and nutrition claims',
            self::DIETARY_CLAIMS_SUSPEND => 'Suspend dietary claims',
            self::DIETARY_CLAIMS_VIEW_AUDIT => 'View dietary claim audit history',
        ];
    }

    /**
     * @return list<string>
     */
    public static function resourcePermissions(): array
    {
        $permissions = [];

        foreach ([...self::LOOKUP_SUBJECTS, 'Recipe'] as $subject) {
            foreach (self::RESOURCE_ACTIONS as $action) {
                $permissions[] = "{$action}:{$subject}";
            }
        }

        foreach (self::ROLE_ACTIONS as $action) {
            $permissions[] = "{$action}:Role";
        }

        return $permissions;
    }

    /**
     * @return list<string>
     */
    public static function allPermissions(): array
    {
        return [...self::resourcePermissions(), ...array_keys(self::customLabels())];
    }

    /**
     * Default permissions per role. Force-delete is deliberately granted to nobody.
     *
     * @return array<string, list<string>>
     */
    public static function defaultRolePermissions(): array
    {
        $readOnlyLookups = collect(self::LOOKUP_SUBJECTS)
            ->flatMap(fn (string $subject): array => ["ViewAny:{$subject}", "View:{$subject}"])
            ->all();

        $planner = [self::PLANNER_USE, self::HOUSEHOLDS_CREATE];

        $contributor = [
            self::RECIPES_FORK,
            self::VARIATIONS_SUBMIT,
            self::RECIPES_CREATE,
            self::RECIPES_VIEW_OWNED,
            self::RECIPES_UPDATE_OWNED,
            self::RECIPES_DELETE_OWNED,
            self::RECIPES_SUBMIT,
            self::RECIPES_SHARE,
            self::RECIPES_VIEW_SHARED,
            self::RECIPES_PERSONALIZE,
            self::RECIPES_RATE,
            self::DIETARY_CLAIMS_SUGGEST,
            ...$planner,
        ];

        return [
            self::ADMIN => array_values(array_filter(
                self::allPermissions(),
                fn (string $permission): bool => ! str_starts_with($permission, 'ForceDelete'),
            )),
            self::EDITOR => [
                ...$readOnlyLookups,
                'ViewAny:Recipe',
                'View:Recipe',
                'Create:Recipe',
                'Update:Recipe',
                ...$contributor,
                self::RECIPES_REVIEW,
                self::VARIATIONS_REVIEW,
                self::REVISIONS_VIEW_HISTORY,
                self::DIETARY_CLAIMS_VIEW,
                self::DIETARY_CLAIMS_FLAG,
            ],
            self::CONTRIBUTOR => $contributor,
            self::MEMBER => [
                self::RECIPES_FORK,
                self::RECIPES_VIEW_SHARED,
                self::RECIPES_PERSONALIZE,
                self::RECIPES_RATE,
                self::CONTRIBUTORS_REQUEST,
                ...$planner,
            ],
        ];
    }
}
