# Recipe variations: Stage 4 publication workflow

Public submissions remain disabled in `config/recipe-variations.php`. This stage implements and tests the publication backend and member submission controls. Stage 5 now supplies the rights-evidence and source-comparison review interface described below; integrations and release validation remain later stages.

## Frozen first publication

`RecipeVariationPublishingService::submit()` re-queries and locks the submitter and destination, verifies approved contributor/editor/administrator eligibility and record policy, checks the editor's content version, and creates the destination's own first revision. The recipe remains private with pending status. Members cannot gain submission eligibility from the permission flag alone.

The revision contains the normalized exact recipe payload and immutable media metadata. New `publication_context` and `publication_context_hash` columns freeze bilingual change summaries, rights declarations, the exact source permission version, license conditions, original provenance identity, involved users and SHA-256 hashes of uploaded image files. Context is hidden from ordinary model serialization. The existing append-only revision audit trail records submission and review decisions.

Both summaries are required and must be meaningful in length. An unchanged recipe, title/summary/translation-only changes and proportional serving adjustments do not automatically qualify. Structural differences are an editorial aid, never copyright clearance. Approval additionally requires an independent human confirmation of meaningful changes and a review note.

The member editor saves current form content before submitting, so unsaved changes cannot silently differ from the submission. The private preview shows pending status and permits authorized withdrawal. Rejection or withdrawal retains the frozen revision and returns the never-published recipe to a private editable draft; re-submission creates another revision.

## Rights clearance

The applicable public-use grant is the latest verified decision for the exact historical source revision, rather than an unrelated current source revision. It must authorize text reproduction and public adaptation, satisfy commercial-use requirements, and have no active suspension or NoDerivatives restriction. Known Creative Commons identifiers cannot be overridden by contradictory permissive flags. BY attribution and ShareAlike requirements are checked, and the approved public license/conditions are frozen with the submission.

An administrator with `recipes.variations.manage_rights` must call the separate `reviewRights()` decision path. A substantive reason and explicit confirmation that actual license/evidence conditions have been checked are required. Private review reasons stay in internal audit metadata. Editorial approval alone cannot clear rights.

Publication revalidates the exact permission version, current restrictions, contributor privileges, cover, media identity and file hashes. A changed rights decision requires a fresh submission and clearance. Suspension blocks publication without deleting private user data. Source and evidence row locks serialize relevant permission changes with decisions.

The creator, owner, submitter, revision editors and recorded private/media editors cannot approve or clear their own work, including administrators. Administrative media edits and private editor changes are recorded in the existing durable audit table. The migration records when reliable edit tracking began. Older drafts with unknown edit history fail closed for public submission; their private data is retained and history needs editorial assessment before any release path is provided. No historical editor identities are fabricated.

## Database enforcement

`2026_11_02_190000_freeze_variation_publication_submissions.php` adds the context columns and PostgreSQL guards. Pending variation recipe rows, translations, ingredient groups/lines, steps, media relationships, taxonomy and change summaries cannot be changed outside the revision publisher. This pending freeze applies even in monitor mode. Submitted publication context cannot be rewritten.

Public submission and approval additionally require the existing content guard to be in `enforce` mode. This stage does not change the deployed guard mode. A direct variation-publication row write is rejected outside the publisher. Legacy recipe publication services remain blocked for variations.

Migration rollback refuses to discard retained publication contexts. The earlier provenance migration test now unwinds this dependent migration before testing its own legacy backfill, then reapplies it.

## Publication and future history

`RecipeRevisionService::approve()` publishes first-time variations through the existing transactional applier. It verifies resulting live content against the reviewed hash, sets the current published revision and makes the recipe public in the same transaction. Source provenance is never rewritten. The existing quota counting rule excludes the recipe only after successful public approval; pending submissions still count.

Uploaded variation images stay on protected storage. Anonymous delivery uses the existing authorized media controller once the recipe is actually public. No file is moved onto the public disk during an uncommitted publication transaction. Missing files, changed bytes, source media IDs, reused source video links, foreign/shared assets and inline media markup block approval. Trusted video links retain reviewed identities; externally hosted bytes remain controlled by their provider.

Future public edits use ordinary separate revision drafts, with fresh variation declarations and rights clearance before approval. Restoring a historical variation creates a new draft requiring submission and independent review; it does not publish an administrator's own restoration immediately. Provenance and historical revisions remain intact.

Pending dietary proposals are bound to the exact frozen revision for independent claims review. Publication revalidates claims and does not inherit source approvals or approvals from other revisions.

## Remaining release work

- Stage 5 implementation is present: source comparison, private evidence inspection/download, rights decisions and moderation presentation in the existing Filament revision resource. Interactive browser validation remains outstanding.
- Stage 6 integration implementation and validation are recorded below. Stage 7 still requires full-project release validation.
- Stage 7 automated release validation is recorded below. Deployed PostgreSQL/guard verification, interactive device/accessibility checks and release review remain required before activation.
- Resolve unknown historical edit histories before enabling public submissions for older drafts.
- Obtain the requested Cyprus-qualified intellectual-property review of reuse terms before opening public adaptations.

No application database migration or public feature activation was performed. Tests use the separate disposable PostgreSQL server on port 55432. No packages were added.

## Executed validation

- Initial Stage 4/provenance selection: 37 passed, 121 assertions.
- Related variation, Filament, revision, media and dietary regression selection: 135 passed, 497 assertions.
- Expanded final PostgreSQL selection: 239 passed, 824 assertions. It included Stage 4, earlier variation/provenance tests, real fork concurrency tests, revision foundation/lifecycle/resources, enforced guards, media snapshots, dietary revision checks, quotas, access, sharing and existing publishing/workflow tests.
- Two existing risky notices remain in `RecipeAccessTest::test_public_recipes_are_visible_to_guests_until_made_private` and `RecipeAccessTest::test_private_recipes_may_have_one_language_and_still_render`: tested code does not close its output buffers. Their assertions passed.
- After tightening Creative Commons URL/identifier consistency checks, all 33 publication/fork tests passed again, with 120 assertions. Tests cover contradictory ND/NC/SA flags, deed URLs with query strings and valid ShareAlike metadata.
- `npm run build`, Pint, migration PHP syntax validation and `git diff --check` passed.

An initial test found translation-key ordering falsely identifying a title-only copy as materially changed. Comparison now normalizes both payloads. Future corrections to an already-distinct variation remain reviewable: distinctness is measured against its permanent original source, while unchanged revision submissions are rejected.

The application database, deployed content-guard setting and feature switches were not changed. Validation used the disposable PostgreSQL 16 cluster and a 512 MB test-process limit. Interactive browser/device testing, publication races and full-project release validation remain Stage 7 work. Review-controlled re-publication after taking an already-public variation offline remains blocked by legacy publication entry points; a dedicated restoration path must be assessed before release.

## Stage 5 review interface

The existing Filament revision view now shows staff reviewers the frozen English/Greek change summaries, submitted license conditions, retained media declarations and a comparison against the exact original source revision. Later source edits are not synchronized. Source history is restricted to staff with variation review or rights-management permission. Differences are an editorial aid and do not establish clearance.

Independent authorized administrators can clear or decline rights through the existing transactional service. Clearance requires explicit evidence/conditions confirmation and a substantive private reason. Decisions do not publish the recipe. Private reasons and evidence references remain restricted to authorized rights administrators; other reviewers see only the decision status.

The evidence download route requires authentication, email verification and the existing permission policy. It reads only the dedicated private rights disk, validates the retained SHA-256 checksum, streams the same checked file handle as an attachment, and sends no-store/noindex/nosniff headers. Missing files fail with 404; changed bytes fail with 409. No public evidence URLs are introduced.

Review exposed a Stage 4 ordering defect: the audit relationship defaults to ascending IDs, which took precedence over `latest()`. Publication and the review status now explicitly reset that ordering before selecting the latest rights decision. A later rejection therefore overrides an earlier clearance.

Application feature switches and the application database remain unchanged. Stage 6 integrations and Stage 7 release/device/concurrency validation are still outstanding.

Stage 5 validation: final evidence/publication/Filament revision selection passed 42 tests with 181 assertions on the disposable PostgreSQL server. The earlier expanded selection passed 87 of 88 tests; its only failure was a case-sensitive label assertion in a new test, corrected before the final passing run. Fork, private editor and provenance tests in that selection passed. Pint, PHP syntax checks, route registration and `git diff --check` passed. No interactive browser audit was performed.

## Stage 6 integrations

“What Can I Cook?” now offers verified members an opt-in “Include my private variations” filter when the variation feature is enabled. The scope is public recipes plus that member’s own private variations. The same access scope applies to ingredient and approved-substitute discovery, category/difficulty options, matching, substitution confirmation, missing-ingredient additions and personal planner actions. The checkbox cannot grant access to another member’s data, even in a crafted Livewire request. Private matches are labeled in English and Greek. The matching page explicitly sends private/no-store cache headers.

Private variations continue to use independent recipe and ingredient-line identities. Tests cover scaled shopping quantities after private edits, preserving checked shopping items, personal batch creation, source revision changes, retained private access after source removal, and source-specific substitution approvals being refused for a destination. Personal notes, favorites, ratings and ingredient alternatives remain separate from the source. Replacing an ingredient archives dependent personal changes rather than silently reassigning them.

Household membership does not share a variation. The existing planner rule requires every household member to have recipe access. If an owner-only household already planned a private variation and later adds another member, the unavailable entry reveals no title and contributes no private ingredients to the household shopping list. After independent publication review, variations participate in ordinary public matching, household plans, favorites and ratings, using their own published revision.

Dietary filtering continues to use independently approved public claim records; opting into private matching does not infer dietary clearance. The integration checks exercise private dietary-filter exclusion and existing dietary/revision regressions.

No feature activation or application-database migration was performed. No new dependencies were added for Stage 6. Interactive device/accessibility testing and full-project release/concurrency validation remain Stage 7 work.

Stage 6 validation: 283 tests, 1,019 assertions, no assertion failures or test errors, on the separate disposable PostgreSQL 16 server (port 55432). The selection covered the new 15 integration scenarios, matching, meal plans, shopping lists, households, templates, batch cooking, personal notes/ingredient changes, substitutions, ratings, sharing, dietary revision checks, fork/editor and publication workflows. Production assets built successfully; Pint, PHP syntax, route registration and whitespace checks passed.

Four risky notices remain in existing tests: `PersonalRecipeNotesTest::test_guests_do_not_receive_notes_in_public_recipe_html`, `PersonalRecipeNotesTest::test_authenticated_recipe_pages_are_not_shared_cached_and_show_their_own_note`, `RecipeRatingsTest::test_aggregates_and_recipe_structured_data_use_all_active_genuine_ratings`, and `RecipeRatingsTest::test_greek_recipe_page_uses_greek_rating_interface_strings`. An isolated notes/ratings selection reproduced the four notices with all 50 assertions passing. They were not resolved in Stage 6. Initial integration-fixture errors around editor payload fields, guarded rating assignment and source-specific ingredient identity were corrected before the final regression run.

Both application feature switches were checked after validation and remain false. The application database was not migrated or modified.

## Stage 7 automated release checks

Five new independent-process PostgreSQL tests exercise concurrent first submissions, double approval, approval versus withdrawal, approval versus a later rights rejection, and approval blocked behind an in-flight rights suspension. The suspension test verifies an actual PostgreSQL lock wait before committing the suspension, then checks that the waiting publisher refuses it. Workers require the testing environment and a database name ending in `_test`; no application feature setting is changed. Published outcomes must have one published revision, one approval audit and live content matching the reviewed hash. Other outcomes remain private and internally consistent.

The recipe detail page previously passed a nullable summary to an inline Blade section. Blade interprets null as the start of a buffered section, leaving an output buffer open. The metadata fallback is now explicitly a string. A regression verifies buffer depth across an HTTP recipe request with no optional summary. Existing access, notes and ratings tests no longer produce their earlier buffer notices in the focused selection: 59 tests, 287 assertions, no risky notices.

The editor also has an automated English/Greek DOM check that every rendered form control has an accessible label. This checks accessible names only; it does not replace keyboard, screen-reader, visual-layout or real-browser testing.

Composer manifest validation, installed PHP platform requirements, the production asset build and Pint passed. A read-only check confirmed both feature switches are false and the rights disk is local/private with automatic serving disabled. The configured application database (`127.0.0.1:5432`, database `magirevo`) refused connections, so its migration status and guard mode could not be read. No application migrations, setting changes or feature activation were performed.

No browser executable or browser automation runner is installed in this environment. Interactive checks remain pending on a running review/deployed environment: narrow/mobile and desktop layouts, keyboard navigation and focus, English/Greek switching, image/video controls, submission/withdrawal feedback, independent rights decisions and publication, and accessibility review with assistive technology. Production verification must establish PostgreSQL integrity guards and `enforce` mode before public submissions, resolve older unknown edit histories and the previously recorded restoration limitation, and obtain the requested review of reuse terms before opening public adaptations.

### Stage 7 final executed results

- Full-project standard PHPUnit run: **735 tests passed, 3,371 assertions**, exit code 0, no risky notices. `--fail-on-risky` was enabled. Runtime was 3 minutes 57 seconds with a 512 MB process limit; reported peak memory was 153 MB.
- This includes the five new publication races, the existing fork and general revision concurrency suites, the missing-summary rendering regression and the bilingual editor-label check.
- The previously recorded recipe-page output-buffer notices are resolved by the non-null metadata fallback. No test assertions were weakened or removed.
- The first agent-formatted full run stopped prematurely during an existing contributor-admin test. That test subsequently passed in isolation, and the complete standard-runner selection passed as reported above. No full-suite result is inferred from the interrupted run.
- `npm run build`, Pint, `composer validate --no-check-publish`, `composer check-platform-reqs`, PHP syntax and `git diff --check` passed.
- Final event/JUnit diagnostics were written outside the repository to `/tmp/magirevo-stage7-final.events` and `/tmp/magirevo-stage7-final.xml`.

Automated workspace validation is complete. Stage 7 release sign-off remains pending the interactive browser/device/accessibility work and deployed-database checks described above. The application database was not modified, and both feature switches remain disabled.
