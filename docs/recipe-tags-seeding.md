# Recipe tag catalogue

The supplied dataset is stored in `database/seeders/data/recipe_tags.php`: 338 codes, two translations per code, and 12 groups. Existing installations can contain additional tags; the six legacy tags overlap four dataset codes, giving 340 tags after an otherwise unmodified legacy installation is upgraded.

## Changes

- `database/migrations/2026_11_03_075458_add_group_code_to_tags_table.php`: adds only nullable, indexed `tags.group_code`. No historical migration changes. Rolling this migration back removes grouping data; prefer forward fixes after deployment.
- `app/Enums/TagGroup.php`, `lang/en/tags.php`, `lang/el/tags.php`: known groups with bilingual labels, independent of recipe categories.
- `app/Models/Tag.php`: group assignment and labels, plus editorial scope and predicate that exclude the dietary group even if incorrectly classified as descriptive.
- `database/seeders/RecipeTagSeeder.php`: transactional, schema-aware catalogue import. Uses `updateOrCreate` by code and by the translation relationship's `(tag_id, locale)`. Only creation receives default names, slugs, classification, activity, public-display flag and sort order. Existing null groups are filled; non-null groups remain unchanged. Existing descriptions, names, slugs, disabled states, classifications, ordering, custom tags, pivots and claim records remain intact. Localized slug collisions receive a deterministic code/number suffix. A conflicting classification aborts the entire transaction with the offending code for manual review; no automatic deduplication or reclassification.
- `database/seeders/DatabaseSeeder.php`: calls the catalogue before the legacy tag seeder, so new dietary entries start disabled. Recipe demo seeders are explicitly skipped in production. Existing environments should use the standalone catalogue command, avoiding the other bootstrap seeders and admin-account setup entirely.
- `app/Filament/Resources/Tags/Schemas/TagForm.php`, `Tables/TagsTable.php`: group selection, column and filter under existing tag policies.
- `app/Filament/Resources/Recipes/Schemas/RecipeForm.php`: ordinary-tag autocomplete searches both languages and displays the group. Server validation rejects dietary selections. Filament's constrained relationship synchronization retains existing out-of-scope associations.
- `app/Livewire/RecipeIndex.php`, `resources/views/livewire/recipe-index.blade.php`: grouped ordinary-tag options and filtering. The new control excludes dietary entries. Existing dietary query paths continue to use `DietaryClaimVisibility`.
- `app/Services/DietaryClaimVisibility.php`: excludes dietary-group tags from its editorial branch. Approved-claim checks remain revision-bound and subject to release flags, rule versions and fingerprints.
- `tests/Feature/RecipeTagSeederTest.php`: PostgreSQL seeding, preservation, gating and Filament coverage using transactions against an already migrated test database.

No category model, claim decision service, claim tables, release configuration, Meal Planner or What Can I Cook? changes are required. No recipe-tag associations or dietary claims are seeded. Public release remains disabled with an empty claim-code allowlist. New nutrition and ingredient/allergen catalogue entries have public display disabled; vegetarian and vegan also require the existing revision-specific approval process. Existing states are preserved, including legacy vegetarian/vegan display flags, which alone cannot release claims.

## Existing database audit

The read-only pre-implementation audit found six tags, zero duplicate codes and zero duplicate `(tag_id, locale)` keys. PostgreSQL already has unique indexes on `tags.code`, `tag_translations(tag_id, locale)`, `tag_translations(locale, slug)` and `recipe_tag(recipe_id, tag_id)`. No new unique constraint or deduplication migration is necessary. Audit other environments before deployment; unexpected conflicts should be reviewed manually.

## Safe execution

Existing environment, with the application's prior migrations already applied:

```sh
php artisan migrate --path=database/migrations/2026_11_03_075458_add_group_code_to_tags_table.php --force
php artisan db:seed --class=RecipeTagSeeder --force
```

The seeder can be run again with the same command. It reports dataset counts, including pre-existing entries. Counts may differ from all-table totals because custom and legacy tags remain.

Empty production installation, with production environment configured:

```sh
php artisan migrate --force
php artisan db:seed --class=DatabaseSeeder --force
```

For only the tag catalogue on an empty migrated database:

```sh
php artisan db:seed --class=RecipeTagSeeder --force
```

Do not run database reset commands. The standalone seeder does not run recipe or recipe-content seeders. No dietary public release or new dietary public filter is enabled by these commands.

## Dataset counts

| Group | Tags |
| --- | ---: |
| cuisine | 39 |
| cooking_method | 32 |
| equipment | 22 |
| occasion | 26 |
| preparation_time | 18 |
| flavour | 29 |
| texture | 20 |
| main_ingredient | 50 |
| recipe_style | 29 |
| season | 16 |
| practical | 27 |
| dietary_claim | 30 |
| **Total** | **338** |

## Verification

Use an isolated, empty PostgreSQL database. Run ordinary `artisan migrate` before the new transactional test class; it does not reset databases:

```sh
DB_DATABASE=your_isolated_test_database php artisan migrate --env=testing --force
DB_DATABASE=your_isolated_test_database vendor/bin/phpunit tests/Feature/RecipeTagSeederTest.php
```

The repository's older tests use Laravel's `RefreshDatabase`, which normally invokes a reset. During this task they are run only against the separately migrated temporary PostgreSQL server, with a temporary PHPUnit bootstrap setting `RefreshDatabaseState::$migrated = true` to prevent that reset. No application or existing test database is reset.

### Results from this implementation

- Fresh PostgreSQL 16 schema: all existing migrations plus the new additive migration completed successfully using ordinary `migrate`.
- Targeted regression run: **123 passed, 523 assertions, 72.863 seconds**. Included `RecipeTagSeederTest` (11 tests), `DietaryClaimsTest`, `DietaryClaimRevisionTest`, `DietaryClaimReportAuthorizationTest`, `SeederProtectionTest`, `LookupAdminTest`, `RecipeAdminTest`, `WhatCanICookTest`, and `MealPlanTest`.
- Catalogue tests confirmed 338 tags / 676 translations on an empty schema, identical record snapshots after a second run, and preservation of legacy/custom tags, text, disabled states, pivots, claim states and audit records. A legacy six-tag catalogue upgrades to 340 tags; the customization test also retains an extra custom tag, giving 341.
- Production `DatabaseSeeder` ran twice successfully in isolation: 340 tags / 680 translations, zero recipes, zero associations, zero claims.
- Editor tests confirmed bilingual ordinary-tag search, rejection of dietary selections, and preservation of a pre-existing dietary association when ordinary tags are saved.
- Pint completed successfully on the changed PHP files; `git diff --check` passed.
- Initial test-only assertion/invocation issues were corrected; the final targeted run has no failures or skipped tests.

The initial implementation audited the application database read-only. The subsequent explicitly requested execution is recorded below. The full repository suite and real-browser UI tests were not run. Existing production environments still require their own duplicate/index audit and execution of the commands above. No dietary public release decision was made.


### Requested execution on the configured database (2026-11-03)

The JSON tags match the existing 338-entry PHP dataset after mapping `name_en`/`name_el` to `en`/`el`. The 12 EN/EL group labels were updated to the supplied JSON wording in the language files. The canonical PHP dataset continues to supply the seeder without duplicate catalogue records.

Applied only `2026_11_03_075458_add_group_code_to_tags_table` and ran `php artisan db:seed --class=RecipeTagSeeder --force --no-interaction` on database `magirevo`. The first run produced 340 total tags and 680 translations: 338 dataset tags with 676 translations, plus the two retained legacy tags (`quick`, `baking`). Group counts match the dataset table above; the two legacy tags have no assigned group.

The before/after audit verified that all 6 pre-existing tags retained their content and states, all 12 pre-existing translations remained identical, all 10 recipe-tag associations remained identical, and no dietary claims or claim audits were created. The only updates to existing tag rows were missing group assignments and their update timestamps. Public dietary release configuration was unchanged.

The second seed run produced identical complete snapshots of tags, translations, associations, claims and audits. The two affected UI tests passed (19 assertions) against the isolated PostgreSQL server after the group-label update. Pint and `git diff --check` passed.
