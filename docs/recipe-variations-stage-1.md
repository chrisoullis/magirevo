# Recipe variations: Stage 1

The database and provenance foundation is implemented. Fork creation, the member editor, publication and editorial review are later stages. Both switches in `config/recipe-variations.php` remain false; registering permissions does not enable an endpoint or authorize a fork.

## Schema

Three new migrations run after the existing revision migrations:

1. `2026_11_02_180000_create_recipe_reuse_permissions_table.php` adds versioned rights decisions for an exact retained source revision, evidence references and checksums, license conditions, reuse restrictions, reviewer decisions and suspensions.
2. `2026_11_02_180100_extend_recipe_derivations_with_provenance.php` moves the existing source pointer to `recipes.derived_from_recipe_id`, enriches existing derivations, and adds translated change summaries and PostgreSQL integrity triggers.
3. `2026_11_02_180200_grant_recipe_variation_permissions.php` registers dotted permissions and grants only the new permissions to the appropriate existing roles. Existing permission customizations are retained.

Existing derivations are backfilled and marked `provenance_version = 0`. Missing historical rights evidence is not fabricated. All new derivations must use version 1 and provide the exact source revision, verified evidence, owner/creator, license and attribution snapshots, actor-scoped UUID idempotency key and request fingerprint. The old `recipe_derivations.source_recipe_id` column is removed after backfill, leaving one authoritative source pointer. Rollback restores it from that pointer.

Migrations stop if historical source relationships are cyclic or revision ownership is inconsistent. They do not repair or delete such records automatically. Existing migration files are unchanged.

## Retention and integrity

- Source relationships cannot be rewritten or silently unlinked.
- New recursive variations are rejected.
- A deferred constraint requires every derived recipe to have provenance by transaction completion. The recipe and provenance must therefore be inserted in one transaction.
- Source and evidence revisions must belong to the identified source recipe.
- Original provenance fields are immutable; translated change summaries remain editable. Publication must freeze those summaries in a later stage.
- Source recipes and revisions cannot be hard-deleted while referenced. Soft deletion preserves relationships and historical snapshots.
- Hard deletion of a destination removes its own derivation through the existing cascade, without deleting the source or rights records.
- Rights decisions cannot be changed or deleted. To review pending evidence, revise conditions or restore permission after suspension, insert a new version with `supersedes_id`. The original decision remains retained.
- A rights version can be suspended once with an actor and reason; it cannot be unsuspended or have its suspension history rewritten.
- Suspension does not delete an existing variation. Later services must check both historical conditions and current restrictions before sharing or publishing.
- TRUNCATE is refused for provenance and rights records.

The database constraints establish record consistency, not legal clearance. Later services must authorize every actor, resolve the applicable latest rights decision under locks, enforce contributor eligibility, quotas and license conditions, and audit review actions. Database ownership remains a trusted administrative boundary.

## Models and authorization

`Recipe` has `derivedFrom`, `variations`, `derivation` and `reusePermissions` relationships. `RecipeDerivation` uses Astrotomic translations for `change_summary`. Provenance and rights models reject mass assignment; trusted services must explicitly construct validated records. Internal evidence references and attribution snapshots are hidden from ordinary model serialization.

`RecipeReusePermissionPolicy` restricts evidence access and rights creation/suspension to verified administrators holding `recipes.variations.manage_rights`. Historical decisions cannot be updated or deleted through policy abilities.

Members receive `recipes.fork` but do not receive ordinary recipe creation or variation submission rights. Contributors receive variation submission rights; editors receive variation review rights. Record-level fork and publication authorization is implemented in later stages.

## Evidence storage

The dedicated `recipe_rights` filesystem disk uses `storage/app/private/recipe-rights`, private visibility, and no automatic serving route. No evidence document is copied or uploaded in Stage 1. A later evidence download endpoint must authorize the rights policy; stored evidence paths must never be rendered as public links.

## Validation

Use a disposable PostgreSQL database. Do not run RefreshDatabase tests against the application database. Coverage is in `tests/Feature/RecipeVariationProvenanceTest.php`, with existing recipe revision, quota, access and sharing tests used for related regression checks.

This stage does not change the content guard mode or close the first-publication revision gaps identified in Stage 0. Those remain release blockers for public variations.

### Executed results

- Stage 1 provenance tests: 19 passed, 65 assertions, PostgreSQL 16.
- Combined provenance, revision foundation/lifecycle, quota, access and sharing selection: 122 passed, 396 assertions, no assertion failures or test errors. This run used `-d memory_limit=512M`.
- Two existing `RecipeAccessTest` methods were marked risky because tested code did not close its output buffers: `test_public_recipes_are_visible_to_guests_until_made_private` and `test_private_recipes_may_have_one_language_and_still_render`. Both passed their assertions; the notices remain unresolved in Stage 1.
- An earlier combined run at the default 128 MB CLI limit terminated prematurely during `test_private_uploads_go_to_the_private_disk_and_are_not_publicly_reachable`. That test passed independently (10 assertions), and the combined selection completed with the higher process limit. No application memory setting was changed.
- Initial validation found and corrected a factory-state mistake in the new tests and a migration reset issue: PostgreSQL functions survive table resets, so their definitions now use `CREATE OR REPLACE FUNCTION`.
- Pint, migration PHP syntax checks and `git diff --check` passed.

Tests ran against a disposable cluster under `/tmp` on port 55432 with database `the_sunday_table_test`. The application database was not migrated or modified. The full application regression suite and real fork concurrency scenarios belong to subsequent stages.
