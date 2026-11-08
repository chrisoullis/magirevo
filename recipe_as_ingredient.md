# Recipe as an Ingredient — Nested Recipes / Sub-recipes

**Project:** Existing bilingual (English/Greek) Laravel cooking website  
**Status:** Stages 0–7 implemented and validated; enabled in the local application. See final release checkpoint.  
**Execution directive:** Audit existing work, then implement safely in stages; do **not** stop after Stage 0 unless an important blocker requires a decision.  
**Primary example:** Chicken souvlaki includes **200 g tzatziki**, where tzatziki is itself an independently managed recipe.

> This file is the feature's implementation specification **and resumable work log**. Preserve the current architecture, including partially completed work, rather than starting a competing recipe system. If instructions conflict with actual schema or established security guarantees, document the conflict and implement the safest compatible approach, or stop for an essential decision.

## Codex operating rules — READ FIRST

1. Read this entire file, `AGENTS.md` (if present), repository instructions and the current Git/database state. Inspect before coding. Check `git status`, `git diff`, untracked files, applied migrations and relevant tests. **Never overwrite uncommitted work**.
2. Start with Stage 0 (audit), update the checkpoint, and proceed through the remaining stages **without unnecessary approval requests**. Stop only for destructive operations, unresolved rights/privacy decisions, incompatible architecture, or other meaningful blockers.
3. Preserve all data. Never run `migrate:fresh`, `db:wipe`, truncate tables, rewrite applied migrations or run destructive sample seeders. Prefer additive migrations, idempotent backfills and compatibility adapters. Take and verify a database backup before migrations when feasible.
4. **Usage limit:** If Codex can obtain an authoritative **remaining percentage for the relevant active usage limit**, check before each stage and periodically during long operations. When remaining usage is **10% or less**, **do not start new work**; finish/roll back the current atomic operation, write an accurate checkpoint below, then stop and provide a handoff.
5. **Do not pretend quota is observable.** `/status` is often an interactive command and its value may not be machine-readable by the agent. Never infer remaining quota from token estimates or invent a percentage. If unavailable, write `Usage remaining: unavailable / unverified`, checkpoint after every meaningful unit, and ask the user to inspect `/status` or supply the remaining percentage. A markdown instruction alone **cannot guarantee an automatic stop** without an actual usage API, hook or external monitor.
6. Before a likely interruption, record: stage, work completed, changed files, migrations created/applied, tests executed and their exact outcomes, known failures, decisions, blockers and next safe action. No passwords, tokens or other secrets in this file.
7. At the beginning of a future Codex session, read the checkpoint **and re-verify Git and database state** before resuming. Never assume a checkpoint alone proves a migration or transaction completed.
8. Reuse existing Laravel, PostgreSQL, Livewire 4, Alpine.js, Tailwind, Filament, Astrotomic translations, Recipe Revisions, `SyncRecipeContent`, Recipe access policies, servings/scaling, What Can I Cook?, shopping lists, meal planner, ingredient groups, dietary verification and Make It Your Own. Verify current implementation rather than assuming every module is finished.
9. Keep Filament and frontend Creator Studio on the same **domain logic, data contract, validation and publishing**. Reuse compatible form components when safe; separate presentation is acceptable. Never grant frontend users Filament administration rights.
10. Execute meaningful tests after each stage. Do not disable tests or claim unexecuted tests passed; distinguish existing baseline failures from new regressions. Work on the existing repository; do not create a separate project.

## Implementation checkpoint — Codex MUST keep this updated

<!-- CODEX_CHECKPOINT_START -->
- **Last updated (UTC):** 2026-11-08 07:48 UTC (final release reconciliation below)
- **Current stage:** Stage 7 complete; local nested recipes enabled. Earlier audit entries below are historical; the final release checkpoint supersedes their pending items.
- **Repository / branch / commit:** /workspaces/magirevo; main; 871e800. Large pre-existing uncommitted implementation retained, including activated cooking sessions/groups and the six Symfony security patches from this conversation. No reset/stash/commits.
- **Usage remaining:** unavailable / unverified. No authoritative quota tool exposed; human /status update requested. No percentage inferred.
- **Setup:** PHP 8.4.15/Composer 2.10.3 verified. Required Boost installation/guidelines already completed in this conversation; relevant Laravel/testing skills remain applicable.
- **Schema:** Unified typed recipe_ingredients (line_type, stable line_uuid, pinned component recipe/revision, basis, prepared mapping) plus recipe_yields, recipe_revision_dependencies and per-entry component modes already exist. All six November 7 migrations are applied in application batch 30; test history also verified applied. No new migration/backfill/backup needed or performed at audit.
- **Stages already implemented pending validation:** schema/integrity/yields, bounded cycle-safe resolver, versioned snapshots/hash/diff/apply, shared Filament/Contributor Studio controls, public/print previews, scratch/prepared shopping and planner integrations, nested dietary invalidation and variation compatibility. Reuse these implementations; do not duplicate tables or editors.
- **Important gaps:** No nested-feature release flag; quantity-source attribution omits nested revision chains; presenter performs ingredient queries per expanded leaf; nested cooking checkboxes/timers still use a separate browser-local mechanism rather than the persistent shared session progress engine. Publication/dependency concurrency still needs a focused audit/test. Existing media/rights/access checks must be preserved.
- **Architectural decisions:** Keep exact child revision pinning, including superseded-but-published revisions, subject to current privacy/safety access. New child publication never rewrites parent content or quantities. Raw nested ingredients (not child-approved claims or prepared-shopping choices) drive dietary evaluation. Legacy manual quantities remain explicitly unresolved for shopping rather than fabricated; quantity basis uses existing `weight` code for the spec's mass concept.
- **Baseline tests:** Eight existing Nested* feature files running separately via `XDEBUG_MODE=off vendor/bin/phpunit --bootstrap=tests/Support/PreserveDatabaseBootstrap.php`; results to follow. No reset-based historical concurrency tests executed.
- **Files modified in this task so far:** recipe_as_ingredient.md checkpoint only. No application code or data changed during Stage 0.
- **Next safe action:** Finish baseline runs; reconcile Stages 1–3 only where real gaps exist; implement stable nested source revision attribution, then shared Cooking Mode contexts and remaining integration/release checks with checkpoints.

### Stage 0 gap analysis
| Component | Existing implementation | Intended design / disposition | Migration/data risk | Files | Coverage |
| --- | --- | --- | --- | --- | --- |
| Typed lines/yields | Unified ingredient/component ordering, immutable UUIDs, composite ownership/revision FKs, verified weight/volume yields | Keep existing schema and codes; verify constraints/legacy/manual behavior | Applied migrations must not be rewritten | RecipeIngredient, RecipeYield, Nov 7 foundation migration | NestedRecipeFoundationTest |
| Graph/scaling | Resolver bounds depth/components/leaves, validates pinned sources, rational nested amounts and prepared mapping | Keep; add exact nested revision-chain provenance and assess repeated-branch cost | No schema change anticipated | RecipeComponentResolver, QuantityLine | NestedRecipeResolverTest |
| Revisions/publication | Snapshot v3 includes prior v2 components/yields/units, hashes, immutable dependencies, rollback/adapters | Keep; review source withdrawal/publication races | No immutable history rewrite | RecipeRevision* services, RecipePublishingService | NestedRecipeRevisionIntegrationTest |
| Shared editors | Searchable typed lines, yields, preview label, pinning, group/reorder, validation | Keep common schema/service; add a proper release switch without data loss | Existing saved components must survive flag changes | Recipes/RecipeEditor*, RecipeComponentEligibility | NestedRecipeEditorTest, RecipeEditorParityTest |
| Public/cooking | Authorized pinned previews/print and root persistent sessions; child checkboxes/timers are local-only | Replace child cooking controls with shared context/session engine; preserve parent state | Progress adapter required; no second timer engine | CookingSessionProgress, CookingContentService, app.js, components views | NestedPublicRecipeTest; new context coverage needed |
| Shopping/planner/discovery | Recursive scratch/prepared, per-occurrence modes, explicit generation, household audience checks | Keep; ensure revision-chain provenance and no hidden quantity invention | No quantity-rewriting backfill | PlanIngredientCollector, ShoppingListService, MealPlanService, WhatCanICook | NestedPlannerIntegrationTest and existing regressions |
| Dietary/rights | Exact nested fingerprints, reverse invalidation, no claim inheritance, private source denial, sanitized forks | Keep; validate source access/rights on all exports | Claims remain fail-closed | RecipeComponentDietaryTraversal/InvalidationService, RecipeForkService | NestedRecipeDietaryIntegrationTest, NestedRecipeVariationCompatibilityTest |

### Stages 1–2 reconciliation checkpoint — schema, quantities and provenance
- **Updated UTC:** 2026-11-08 06:35:17 UTC. Existing schema/yield/identity/graph/revision architecture retained; no migration created/applied in this unit.
- **Stage 1:** Foundation constraints already implemented. Replaced the legacy migration test's backward rollback with a forward-only upgrade in a new transaction-scoped nested_upgrade schema; skips only the unrelated optional trigram-index migration because extension operators live in public. The scratch namespace rolls back with the test. Applied migrations and existing tables are never reversed. Verified default ingredient typing/UUID backfill and byte-for-byte immutable legacy revision preservation.
- **Stage 2 implemented fixes:** Installed Brick Math's toBigDecimal accepts no scale/rounding arguments. Resolver now uses toScale explicitly, fixing repeating-fraction exceptions and retaining four-decimal output. Exact rational ratios remain unrounded through nested batches. Positive leaf amounts that would round to zero fail visibly as unresolved instead of generating misleading zero shopping quantities. Added component dimension/quantity checks to the graph validator. Source provenance now includes leaf recipe_revision_id plus the complete revision_path; prepared sources retain the exact mapped component revision. Added invocation-local revision/ingredient caches and batch-loaded presenter ingredient translations, eliminating repeated per-occurrence revision/ingredient hydration.
- **Tests:** Baseline FoundationTest: 44 passed/1 migration rollback error; ResolverTest: 9 passed/7 numeric-format failures revealing the Brick API issue. Five other baseline Nested* files passed individually. Variation compatibility had one additional fixture error: it attempted to redefine a unit that the historical measurement guard correctly protects; its test is being reconciled without weakening the guard. First focused corrected unit: **63 passed / 151 assertions**. Expanded final Foundation+Resolver run: **65 passed / 155 assertions**, including repeating fractions, precision underflow, complete nested revision/occurrence provenance and 20 repeated branches reusing revision/ingredient queries. No failed assertions removed or disabled.
- **Files changed:** app/Services/RecipeComponentResolver.php; app/Services/RecipeContentPresenter.php; tests/Feature/NestedRecipeFoundationTest.php; tests/Feature/NestedRecipeResolverTest.php; recipe_as_ingredient.md. Existing changes in these files preserved.
- **Known remaining gaps:** Stage 3 release switch; Stage 4 nested instructions/progress/timers should reuse persistent cooking contexts; Stage 5 persisted generated-shopping source requirements currently discarded by ShoppingListService::forShopping and have no column; Stage 6 rights/invalidation review; Stage 7 publication/concurrency and end-to-end integration checks. Schema migration will be additive and backed up before applying if persisted shopping provenance is implemented.
- **Usage remaining:** unavailable / unverified. No percentage or threshold check claimed. Continue with Stage 3 and checkpoint before the next meaningful unit.

### Stages 3–4 checkpoint — reuse shared editor and persistent cooking contexts
- **Updated UTC:** 2026-11-08 06:46:27 UTC. Stage 3's pre-existing shared typed-line editor/pinning/validation is retained and its baseline tests pass. A separate release switch is still required before final rollout; no duplicated form or administrator permission grants added.
- **Stage 4 backend implemented:** Added bounded immutable component-path context selection to existing CookingSessionProgress and its existing API allowlist. Root and each authored component occurrence retain independent step/group/checklist/timer state inside one session JSON document, with the same versioned lifecycle events, owner policy, exact revision binding and graph-access checks. Parent instructions and timers remain unchanged when entering/leaving child contexts. Child quantities follow parent servings; independent child serving overrides are rejected. A started unfinished child keeps the session active/resumable even after parent steps are completed. No second timer engine/session table introduced.
- **Stage 4 frontend implemented pending browser validation:** Existing overlay projects the selected context, provides Return to parent recipe, reuses group navigation and progress controls, and aggregates root/child timers into the same panel. Authenticated context progress uses PostgreSQL; guest context state uses existing revision-bound browser persistence. Enhanced-mode component checkboxes/local timers were replaced with the shared controls; disabled-session legacy compatibility stays available. Child references intentionally omit media rather than implying reproduction rights; root media protections are unchanged.
- **Tests:** First context/API+existing cooking run: **53 passed / 215 assertions**. Expanded final run including unfinished-child resume behavior: **54 passed / 218 assertions**. All four JavaScript test files pass, with new context isolation/restore/unknown-path coverage. Node syntax checks pass; Vite build succeeded before the latest small state-completion adjustment, so rebuild again before browser checks. Variation compatibility corrected to assert immutable unit redefinition is rejected, while still proving fork/editor frozen-unit preservation: **5 passed / 29 assertions**. No guard weakened.
- **Changed files this unit:** CookingSessionProgress.php, CookingSessionService.php, CookingContentService.php, RecipeComponentResolver.php (resolved child servings); resources/js/cooking-state.js, cooking-session-client.js, app.js; resources/views/recipes/show.blade.php; lang/en/nested_public.php, lang/el/nested_public.php; tests/Feature/NestedCookingContextTest.php (new); tests/js/parallel-cooking.test.js; tests/Feature/NestedRecipeVariationCompatibilityTest.php; this checkpoint.
- **Stage 5 starting:** Shopping provenance is dropped by the existing forShopping adapter and not persisted. Created additive migration 2026_11_08_064349_add_source_requirements_to_shopping_list_items_table.php (JSONB list, empty default for unknown legacy requirements, guarded rollback). Not applied yet at this checkpoint. Application pg_dump backup/TOC verification is running before any schema change; do not assume completion until verified.
- **Data/usage:** No application recipe edits or applied historical migration changes. Usage remaining: unavailable / unverified; no authoritative percentage or threshold claim. Next: verify backup, apply only additive provenance migration, retain source paths during explicit regeneration, then finish flag/access/publication concurrency and real nested-context browser tests.

### Stages 5–6 checkpoint — shopping provenance, substitution identity and release safety
- **Updated UTC:** 2026-11-08 07:29:45 UTC. Core pre-existing editor/domain integrations retained; final browser/release checks in progress.
- **Backup/migration verified:** Full application custom-format PostgreSQL backup `/tmp/nested-before-provenance-20261108-064607.dump`, 581474 bytes, SHA-256 c6d436489c7fe54086cec57cfcbd640e282d3ac6e9ceeb8baf433a10457b8a3a. Archive TOC verified with pg_restore --list; permissions 0600. Backup credentials stayed in process environment, never printed. Applied only additive 2026_11_08_064349_add_source_requirements_to_shopping_list_items_table to application and test databases. All **62 original content/integration table fingerprints matched** after migration using original column lists. No recipe/quantity/history backfill, destructive reset or applied migration rewrite. Old shopping provenance defaults to unknown empty lists until explicit regeneration.
- **Stage 5 complete:** Shopping generation now retains source paths/line identities, exact nested revision chains, planned revision and selected servings in hidden source_requirements JSONB. forShopping no longer discards source data. Checked/stale/manual item behavior stays on existing regeneration path, and semantically identical JSONB provenance does not increment versions. Newly approved rows with null IDs in immutable payloads resolve through the existing resolved_ids mapping, without rewriting frozen snapshots.
- **Substitution safety:** Added one shared IngredientSubstitution::appliesToResolvedSource rule, reused by What Can I Cook, planner confirmation/display and shopping collection. Bound approvals must match the exact resolved child revision rather than a newer live child. General and legacy unversioned/private retained behavior is preserved without transferring dietary claims. New tests cover wrong-revision refusal and resolved line identities. Initial two new tests attempted self-approval and were correctly denied; corrected fixtures to use a separate reviewer, preserving policy. Final revision/planner/discovery/shopping run: **57 passed / 215 assertions**. Earlier shopping/presenter run: **32 passed / 133 assertions**.
- **Stage 6 reconciliation:** Existing nested dietary fingerprints/reverse invalidation and sanitized reference-only Make It Your Own behavior retained. Archived sources now fail closed in picker/graph/dietary traversal even for their owner. Added default-off NESTED_RECIPES_ENABLED switch across creation/picker/graph and public/dietary availability; disabling it retains saved rows/snapshots and ordinary recipes. Test configuration enables it explicitly; local application rollout is pending final checks. Shared bilingual Add ingredient or sub-recipe action added for clarity.
- **Publication safety:** Public graph validation now locks source recipes FOR SHARE in ascending ID order within each dependency level while in a transaction. Publication/submit/approval/rollback/withdraw transaction boundaries retry deadlocks up to five times; existing parent row locks and exact pin validation stay in place. New independent-worker parent-publication vs child-withdrawal race passed with no partial publication or unreadable public success; all six isolated concurrency checks passed (**6 tests / 71 assertions**). Existing public test-schema table fingerprints were unchanged.
- **Final regression so far:** **29 independent feature files: 512 tests passed / 1847 assertions**, no failures, covering all Nested* cases, cooking/groups/revisions, shopping/planner/discovery, household/dietary, access/sharing/forks, both editors and secure step media. These runs use the permanent transaction-only bootstrap and avoid reset-based historical concurrency classes. JUnit/logs: /tmp/nested-final-<class>.xml/.log. Full monolithic suite not claimed. Live nested cooking verified shared root/child timers, API persistence, refresh and return-to-parent; final bilingual editor selector check is being diagnosed before claiming the complete browser run passed.
- **Files changed this unit:** database/migrations/2026_11_08_064349_add_source_requirements_to_shopping_list_items_table.php; ShoppingListItem.php; IngredientSubstitution.php; PlanIngredientCollector.php; ShoppingListService.php; MealPlanService.php; WhatCanICook.php; RecipeComponentResolver.php; RecipeComponentDietaryTraversal.php; RecipeComponentEligibility.php; RecipeEditorFields.php; RecipePublishingService.php; RecipeRevisionService.php; config/nested-recipes.php; phpunit.xml; .env.example; lang/en/nested.php, lang/el/nested.php and nested_validation translations; tests/Feature/NestedPlannerIntegrationTest.php, NestedRecipeRevisionIntegrationTest.php, NestedRecipeReleaseTest.php; CookingSessionConcurrencyTest.php, CookingSessionWorker.php, CookingReleaseEnvironment.php; tests/browser/cooking-live.cjs; this checkpoint. Exact earlier file changes remain above; all prior uncommitted work preserved.
- **Usage remaining:** unavailable / unverified. No authoritative percentage or threshold claim. Next: resolve browser harness/UI issues if present, run final compatibility and formatting checks, explicitly enable local nested switch only after critical checks pass, verify application content fingerprints again and update the final checklist.

### Stage 7 final release checkpoint — implemented and locally activated
- **Release:** Stages 1–6 reconciled and Stage 7 checks passed. Local `.env` now has `NESTED_RECIPES_ENABLED=true`; configuration cache cleared and resolved configuration verified. Cooking groups and sessions remain enabled. Deployment defaults in config/.env.example stay false for deliberate rollout in other environments.
- **Browser:** The final live two-context Chromium run passed real authentication/CSRF, optimistic conflict recovery, cross-browser child-context resume, refresh/history navigation, independent parent/child timers, return-to-parent progress, keyboard focus, English/Greek Add group and Add ingredient or sub-recipe → Component recipe controls. Axe WCAG A/AA reported zero violations and no page errors. Editor actions now await their actual Livewire mountAction response before inspecting the updated DOM. Browser output: `/tmp/nested-live-browser.log`. History navigation reloaded (`bfcachePersisted=false`); actual persisted bfcache, human screen-reader speech and real media playback are not claimed.
- **Final edge cases:** Private parent without public slugs has authorized child content with no fabricated preview URL; manual child amounts expose a warning and no guessed ingredient quantities; nonzero small quantities are not formatted as zero. All **31 edge-case tests / 139 assertions passed**, covering NestedCookingContextTest, NestedRecipeResolverTest and NestedPublicPresenterTest. The manual test creates its own required lookup code transactionally rather than depending on seeded data.
- **Regression evidence:** Previously completed **29 feature files / 512 passing tests / 1847 assertions**, plus **6 isolated cross-process tests / 71 assertions**; these overlap some of the 31 edge checks, so do not add counts as distinct tests. Four JavaScript test files passed, Vite production build passed, Pint completed, and `git diff --check` passed. No full one-process suite claim. Historical reset-based concurrency tests were not run.
- **Data verification:** Additive shopping provenance migration is applied in application batch 31; November 7 migrations remain applied in batch 30. Final read-only verification confirmed **all 62 original content/integration table fingerprints unchanged**. Existing recipes/revisions/amounts/history were not rewritten. Verified backup and original fingerprint details are recorded in the Stage 5–6 checkpoint above.
- **Rights and compatibility:** Child cooking contexts deliberately omit media; a recipe reference does not grant redistribution rights. Root media still follows existing authorization. Saved component rows and immutable pins survive disabling the flag; inaccessible/archived sources fail closed. Authenticated browser storage holds session identity only.
- **User entry point:** Both editors → Ingredients → Add ingredient or sub-recipe → choose Component recipe. For mass/volume quantities, the source must have a compatible verified yield. Instructions → Add group remains available independently.
- **Remaining work / safe resume:** No known blocking implementation issue remains in this scoped feature. Deployments outside this local workspace must apply the additive migration and explicitly enable their own flag after environment checks. Retain existing uncommitted work and backups; do not reset databases or rerun destructive historical tests. Temporary test fixtures live only in the isolated test namespace. Usage quota remains unavailable; no percentage inferred.
<!-- CODEX_CHECKPOINT_END -->

## 1. Goal and user-facing behavior

Implement **Recipe as an Ingredient / Sub-recipe**: a recipe may contain another eligible recipe as a component alongside ordinary ingredients.

**Example:** Four portions of chicken souvlaki require chicken, pita, tomato and **200 g of tzatziki**. Tzatziki has its own ingredients and instructions, photographs (when permitted), recipe author, revision and potentially a known yield of **500 g per batch**.

User can:

- Add tzatziki as a **Recipe / Sub-recipe** line from the recipe editor, rather than creating a fake ingredient.
- View the sub-recipe's name, required quantity and accessible link to its instructions.
- Choose **Make from Scratch** (expand its ingredients into shopping requirements) or **Use Ready-Made** (add a single suitable shopping item, if a valid purchasable item mapping exists).
- Adjust servings: scale sub-recipe quantity and nested ingredients **only when a mathematically and semantically valid yield/conversion exists**.
- In Cooking Mode, open the linked sub-recipe instructions and return to the parent without losing progress. If the separate Cooking Sessions feature is available, reuse it; do not build a second cooking engine.

### Important principle

A recipe relationship is **not** an ordinary ingredient record. Do not fake the relationship by writing a recipe title into `ingredients`, or conflate recipes with ingredient catalog taxonomy. Preserve the different semantics of ingredient lines vs. nested recipes.

## 2. Stage 0 — Audit and gap analysis (NO CODE CHANGES)

Inspect:

- Git state and preexisting sub-recipe or component code, migrations and applied schema.
- `recipes`, `recipe_translations`, `recipe_ingredients`, ingredient groups, units, scaling modes and serving adjustment services.
- Revision storage (`recipe_revisions`, JSON snapshot schema versions, payload serializer, diffs, content hash, publisher, rollback, guards) and current live-to-revision synchronization.
- Public/private/shared recipe rules (`RecipePolicy`, `RecipeAccessService`), contributor workflows, media authorization and unpublish/revocation policies.
- Filament recipe form/editor, frontend Contributor Recipe Studio, Make It Your Own and source provenance.
- Shopping list collectors/consolidation, shopping item provenance, checked/manual item behavior, existing `is_stale` logic.
- Meal Planner, household permissions, leftovers, batch cooking and revision references.
- What Can I Cook? ingredient matching and approved substitutions.
- Dietary fingerprint/claims and ingredient metadata change observers.
- Recipe pages, structured data, Cooking Mode, current step-group support and tests.
- Existing ingredient units, conversions and whether recipe **yield** is represented as servings, mass or volume.

Write a gap analysis: **component | current implementation | intended design | reuse/change/add | migration/data risk | files | tests**. Add a note to the checkpoint. Proceed only if safe; do not replicate functionality already present.

## 3. Data model (Stage 1)

Choose the minimal schema compatible with current database and revision system; the following is a starting design, not a mandate to duplicate fields:

### `recipe_components` (or equivalent)

- `id` — stable component-line identity.
- `parent_recipe_id` — stable parent recipe ID.
- `component_recipe_id` — referenced standalone recipe ID.
- `component_recipe_revision_id` — immutable eligible component revision used by a published parent (drafts may require a different resolution strategy).
- `ingredient_group_id` — nullable, referencing a group belonging to the parent.
- `position` — ordering among components/ingredients, using a **single deterministic interleaving strategy** for all line types.
- `quantity` — decimal, positive.
- `unit_id` — nullable if compatible.
- `quantity_basis` — explicit enum/code: `servings`, `mass`, `volume`, `whole_batch`, subject to existing unit conventions.
- `notes` / translations only if consistent with current domain model.
- timestamps and safe foreign keys / indexes.

**Avoid two inconsistent ordering sequences.** Assess whether a single recipe-line abstraction or component pivot is safer than independently ordered `recipe_ingredients` and `recipe_components`. Preserve current IDs and integrations; do not restructure existing ingredient rows without a proven need. Never delete existing user substitutions or line references as a side effect.

### Yield model

Support optional verified/declared **yield amount and unit** for the sub-recipe: e.g., `500 g per full batch` or `6 servings`. Reuse any existing output/yield representation. If absent, propose additive nullable fields or a narrowly scoped dedicated model.

- Servings ≠ grams or millilitres. Do not assume unit density or that 1 serving equals 1 unit of weight.
- For mass/volume scaling, require a matching, reliable batch yield and compatible units.
- For `whole_batch`, a quantity of 1 means the whole base batch; for `servings`, scale against base servings.
- Reject zero/negative quantities, missing conversions and unsupported dimensions. Do not silently approximate.
- Keep conversion provenance/precision and appropriate quantity rounding rules.

### Invariants

- Parent and component cannot be the same recipe.
- Reject **indirect cycles** (A → B → C → A), across draft and published dependency graphs as appropriate.
- Limit recursion depth and total expansion count for safety/performance; return a clear editor error instead of silently dropping ingredients.
- Component references must be authorized and eligible to be used by this parent and intended audience.
- Preserve old snapshots and historical link identities. `ON DELETE CASCADE` must not destroy an established recipe, shopping item, personal substitution or audit history.
- Support deletion/unpublishing/revocation with safe historical references and no unauthorized data exposure.

## 4. Revision identity and publication model (Stages 2–3)

**This is a critical architectural decision.** Published parent recipes must not change silently when a component recipe is edited.

- The parent **draft** may display the latest eligible component revision for selection, but record what the editor actually selected; on submit/publish validate and **pin the exact eligible immutable published revision**. Never pin an in-review/draft component for public publication.
- Include component IDs, parent line stable IDs, chosen component revision IDs, quantities, unit basis, group membership, ordering and notes in the **parent revision payload**, content hash, comparisons, approval, rollback and full content guard.
- Validate eligibility and exact revision on submit **and again at publish** within the transaction. If component was deleted, suspended, rights-restricted, or unsafe, **fail closed**, do not silently substitute a newer revision.
- Publishing a new component revision **does not automatically update** a published parent's pinned revision. Parent author/editor may explicitly propose a parent revision to upgrade dependency and review changed quantities/claims.
- Use version-aware snapshot readers to keep old revisions readable. Do not rewrite immutable old snapshots.
- If a sub-recipe is unpublished/revoked/suspended for safety or privacy, **deny access to its content as appropriate even if an older revision was pinned**; show a safe unavailable/needs-review state rather than leaking historical details.
- Consider retention/garbage collection for revision-referenced media and lookup records. Never assume retained IDs imply retained authorized files.
- Ensure the recipe-publication transaction and locking are concurrency-safe; establish a deterministic lock order where nested references are checked.

### Open question to resolve from actual code

For already-public recipes using a component, is it safe to pin the exact component revision permanently? **Default: yes for content stability, subject to security takedowns and access revocation.** Explain any implications for dietary claims and shopper convenience in the Stage 0 report.

## 5. Shared Filament and frontend editor UX (Stage 3)

In an existing ingredient group/line editor, offer a clear choice:

**Line type:** `Ingredient` or `Recipe / Sub-recipe`.

For sub-recipes provide:

- Searchable picker of **eligible accessible recipes** (English/Greek); include an image only where media rights/access permit.
- Clear title, published revision, base servings and yield.
- Quantity and units/basis, with contextual explanations and validation.
- Group placement and drag/reorder within the unified line ordering.
- A preview/link with permission checks.
- Optional note; remove/replace safely.
- Warnings about unsupported scaling, cyclic references, revoked visibility and non-public dependencies.

Keep Filament and frontend creation behavior consistent through shared domain services, validation, normalization and revision persistence. Do not expose private recipes in autocomplete results or through ID tampering. A contributor may not link a private/shared component into public content unless there is an explicit authorized safe distribution model; default to disallow public linking of non-public components.

**Not necessary:** cloning the complete child recipe into the parent or copying its media. Recipe-as-ingredient is a *reference* with exact revision, not Make It Your Own. Distinguish the two features in UI.

## 6. Public recipe page and Cooking Mode (Stage 4)

- Render sub-recipe entries alongside ordinary ingredients in the intended order/group, with amounts and clear **View Recipe / Show Ingredients** actions.
- Only display a child title, ingredients or media when the viewer is authorized. For inaccessible dependency: safe neutral unavailable state (or block public publication beforehand); never reveal protected names or IDs.
- Accessible expandable sub-recipe ingredients; avoid deep automatic expansion on page load. Show nested quantity and whether scaling was available.
- Cooking Mode: open eligible child recipe instructions and return to the original step/group **with progress and timers intact**. If Cooking Sessions/Step Groups are partially implemented, integrate with those modules; do not create a parallel timer/session subsystem. Use explicit parent-child navigation or a bounded cooking-context stack where appropriate.
- Provide bilingual translated labels and paths, keyboard navigation, mobile-friendly design and safe fallback when images/steps unavailable.
- Print layout and Recipe structured-data instructions should include only authorized, truthful information and valid sections; avoid duplicating sub-recipe ingredients in schema if semantically incorrect.

## 7. Shopping Lists, What Can I Cook? and Meal Planner (Stage 5)

### Shopping lists: two explicit modes

**Prepare From Scratch:** recursively expand eligible child recipe lines into ordinary ingredient requirements, scale for the requested sub-recipe quantity, consolidate compatible units once and preserve ingredient source/provenance.

**Use Ready-Made:** add a single item for the prepared product only when a corresponding legitimate shopping ingredient/product mapping exists. The sub-recipe *name* by itself is not guaranteed to correspond to a purchasable catalog ingredient. If missing, prompt for a manual shopping line or a mapping decision; never silently fabricate one.

- Choose the mode per component occurrence when preparing a shopping list; don't make an accidental global preference that changes unrelated meal plans.
- No double-counting: a prepared item and its expanded ingredients must not both be added for the same occurrence.
- Preserve manually added items, checked states and existing consolidation rules; do not silently rewrite generated quantities after parent/child recipe publication changes. Use existing stale/review/regeneration workflows.
- Each derived shopping requirement should retain enough source-path/version info for safe reconciliation, including nested source revision IDs and stable line identities.
- Properly handle repeated sub-recipes shared by multiple parent recipes, nested sub-recipes, scaling, rounding, fixed/manual scaling and cycle detection.
- Fail visibly on unresolved yields, unviewable dependencies and unsupported conversions rather than creating incorrect shopping lists.

### What Can I Cook?

- Decide how nested recipes affect ingredient matching: optionally expand eligible scratch-made components to actual ingredient requirements, respecting access, yields and availability; never pretend a user has a prepared sauce just because they own its raw ingredients without making that interpretation explicit.
- Do not distort existing matching results or bypass approved substitution logic. Document matching mode and tests.
- Never transfer a verified dietary claim via a substituted ingredient or component.

### Meal Planner

- Keep planning entries tied to parent recipe and selected approved revision, and track effective nested dependency revisions where needed.
- Respect household/private access for **every nested component**; do not leak inaccessible recipe metadata through household plans, shopping requirements, notifications or email.
- Display included sub-recipes and selected scratch/ready-made mode. Preserve leftover/batch-cook histories and revision references.
- When any dependency becomes unavailable or changes, indicate that plan/shopping requirements need review; do not automatically rewrite already-generated or checked shopping items.

## 8. Dietary claims and metadata changes (Stage 6)

- Do **not** inherit approved Vegetarian/Vegan/Gluten-Free/High-Protein or other dietary claims from the component recipe.
- Parent-recipe validation must evaluate nested contents against the **exact pinned component revision** and the active current ingredient metadata/rules/evidence; treat unknown composite ingredients and inaccessible dependencies as **unknown/unsafe to claim** (fail closed).
- Parent dietary fingerprint/dependency signature must capture nested revision identities and relevant resolved data. Changing a component's underlying ingredient metadata, validation rules, rights/evidence or safety status must invalidate **affected parent claims immediately**, including already-published parents.
- Track reverse dependencies efficiently for targeted revalidation; use bounded cycle-safe traversal, no unbounded recursive queries.
- Public visibility remains governed by current parent revision, approval, fingerprint and release flags. A new parent revision gets fresh approval; rollback does not inherit claims.
- Keep regulated/high-risk public claims behind existing release policies and legal/nutrition-expert review requirements. Passing tests does not certify nutritional safety or legal compliance.

## 9. Make It Your Own, rights and history (Stage 6)

- Make It Your Own duplicates **permitted** content into an independent private recipe. For nested components, carry over only **eligible references** with their exact source revision IDs, subject to reuse/licensing rights; otherwise omit them with a clear editor warning or require replacement.
- Preserve source provenance and permissions; never copy protected child photos/videos, reviews, ratings, shares, approval records or verified dietary claims.
- If a source/child recipe is removed, don't cascade-delete unrelated derived recipes, meal plans or audit records; maintain lawful historical references and no private leaks.
- An accessible link is **not** automatically permission to redistribute a private child's full ingredients/instructions. Default public parent publication to eligible public children only.

## 10. Data migration, deployment and safety

- Use additive migrations only, with safe foreign keys, indexes and uniqueness constraints. Make initial backfills idempotent if needed.
- Record whether the installed recipe revision schema can serialize new component lines; use a versioned adapter rather than modifying previous immutable revisions.
- Do not run production seeders that write published recipe content directly under guard enforcement. Do not bypass protection for convenience.
- Use transactions, row locks and database constraints for insertion/updates and safe publication. Validate cycles again on publish.
- Use a feature flag to keep nested-recipe creation/display disabled publicly until integrity, permissions, shopping/planner and claim tests pass.
- Avoid N+1 and exponential expansion; apply eager loading, request limits and maximum nested depth. Measure realistic multi-level cases.
- Before migration, verify backup and rollback strategy; do not claim backup exists unless actually created/verified.

## 11. Required tests (throughout, final Stage 7)

**Schema/editor:** Ordinary and sub-recipe lines coexist; English/Greek labels; ordering/grouping; same save result through Filament and frontend; eligible selection; type switching; deletion; stable IDs; legacy compatibility; invalid quantities/units.

**Graph:** Direct and indirect cycles; maximum depth/expansion; simultaneous edits and publication; race between selecting child and child unpublishing; stale/revoked child; deleted lookup/revision/media; rollback/old schema adapters.

**Revision:** Draft isolation; exact pinned child revision; child update doesn't change existing parent public content; content hashing/diff; review approval; rollback; auth guard and no draft leaks.

**Quantities:** Whole batch, servings, compatible grams/ml, invalid/missing yield, rounding, nested scaling, fixed/manual scaling, underflow/zero/negative values.

**Shopping:** Scratch vs ready-made; missing purchasable mapping; no double-count; nested consolidation; preserves checked/manual items; staleness and explicit regeneration; source-path attribution.

**Planner/What Can I Cook?:** Parent and dependency access checks, household access, nested ingredient matching, partial missing ingredients, substitutes, historical plan data and shopping calculations.

**Dietary:** No inherited claims; pending/approved/stale; unknown compounds fail closed; metadata invalidation propagates to parent claims; current-revision visibility and release flags.
pu
**Privacy/media:** Private child not selectable for unauthorized users; no title leakage via search/errors/SEO/exports; secure media; Make It Your Own rights; suspended or deleted recipes.

**UX:** Public component display, bilingual copy, accessible nested views, Cooking Mode return-to-parent preserving progress and active timers, print and structured data.

Use actual PostgreSQL tests where relevant. If a full one-process suite crashes on the existing baseline, run suites per file and report the limitation and exact failures.

## 12. Staged implementation plan

**Stage 0 — Audit (no edits).** Identify reusable code, incomplete prior work, exact migrations/affected files and risks. Update checkpoint and proceed if safe.

**Stage 1 — Schema, yields and dependency graph.** Add minimal safe component representation, explicit quantity basis/yield and cycle checks; DB/policy tests.

**Stage 2 — Domain service, scaling and revision serializer.** Centralize add/update/remove components, quantity resolution, graph traversal, version-aware snapshots and content hashes; transactional publication safeguards; tests.

**Stage 3 — Shared Filament + frontend recipe editor.** Add regular-ingredient/sub-recipe line selection, search, preview, group ordering, validation and draft/revision lifecycle; parity and auth tests.

**Stage 4 — Public recipe display + Cooking Mode.** Bilingual nested component UI, authorized expansion/link, Cooking Session integration where available; accessibility and privacy tests.

**Stage 5 — Shopping Lists, What Can I Cook? and Meal Planner.** Scratch/ready-made modes, recursive expansion, no double-counting, revisions, explicit regeneration and household access; regression tests.

**Stage 6 — Dietary claims, Make It Your Own, media/privacy and release integration.** Dependency invalidation, rights/provenance, suspended references and safe SEO; tests.

**Stage 7 — Final validation.** PostgreSQL integration, cross-process concurrency where feasible, UI parity, bilingual checks, performance, permissions, regressions; release-readiness report. Enable feature only when critical requirements pass.

After **every stage**, update the checkpoint **before** proceeding. If usage is verifiably <=10% remaining, checkpoint and stop. If quota cannot be read, do not invent a percentage; report it accurately and checkpoint conservatively.

## 13. Definition of done

- [x] Eligible sub-recipes can be attached as recipe components in both editors.
- [x] Stable line IDs, ingredient ordering/groups and quantity basis work.
- [x] Valid yield-based scaling works; invalid scaling fails visibly.
- [x] Cyclic/deep dependencies are safely blocked.
- [x] Parent publication pins immutable eligible child revisions and respects unpublish/revoke events.
- [x] Drafts, published snapshots, hashes, diffs and rollback support components.
- [x] Public pages and Cooking Mode are intuitive and authorization-safe.
- [x] Shopping lists support scratch/ready-made with no double count and safe history.
- [x] What Can I Cook? and Meal Planner correctly handle nested components and private/household access.
- [x] Dietary claims are independently assessed; dependent invalidation works.
- [x] Make It Your Own keeps provenance/rights without copying unauthorized data/media.
- [x] Existing data and recipe features are preserved; tests actually pass.
- [x] Checkpoint states completed work, remaining issues and safe resume action accurately.

## 14. Resume prompt for future Codex sessions

> Read `recipe_as_ingredient.md` completely, especially the `Implementation checkpoint`. Check `AGENTS.md`, `git status`, `git diff`, applied migrations and recent tests; reconcile them with the checkpoint. Resume from the next safe unfinished stage. Do not repeat completed work. Keep updating the checkpoint after each stage and before interruptions. Stop if the **verified** relevant Codex usage remaining is **10% or less**; if usage is not machine-readable, report that limitation and never invent a percentage.
