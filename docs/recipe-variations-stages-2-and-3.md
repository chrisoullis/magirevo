# Recipe variations: Stage 2 prerequisite and Stage 3 interface

Stage 2 was absent when Stage 3 was requested. The fork service and its authorization, rights and quota safeguards were implemented as prerequisites, followed by the private member interface. Public publication, moderation and the remaining integration stages are not enabled.

## Services

- `RecipeForkService` re-queries and locks the actor, public source and current reuse permission. It reads only the source's current published revision and checks its payload hash. It creates a separate private draft, new translated slugs, new groups, ingredient lines and steps, and permanent provenance inside the existing per-user quota transaction.
- Actor-scoped UUID request keys are idempotent. Existing successful requests are resolved before a new quota check. Reusing a key for another source is rejected. Independent PostgreSQL-process tests cover repeated submissions and competing requests at quota.
- `RecipeVariationRights` resolves the latest rights decision without falling back to an older permissive grant. Verification, source revision, suspension, text reproduction, private adaptation and NoDerivatives conditions are checked. The default commercial context additionally requires explicit commercial-use permission. Missing or restricted evidence denies copying for administrators as well as members.
- `RecipeVariationText` extracts plain text, omitting source media, embeds, figures/captions and remote addresses. Source media relations, ratings, shares, favorites, notes, substitutions, dietary tags/approvals and revision history are not cloned.
- `RecipeVariationContent` validates and writes private draft content using scoped destination IDs. It supports one-language drafts, translated groups and notes, quantities/ranges, existing units/scaling modes, ordering, steps, categories and ordinary tags. Replacing an ingredient creates a fresh line identity; existing database archival behavior preserves dependent personal changes. A content version prevents stale editor windows from overwriting another save.
- `RecipeVariationMedia` reauthorizes and locks the destination for every mutation. It reuses existing private image upload, validation and step-image optimization actions. Cover/gallery/step photos and trusted YouTube/Vimeo links are supported. Media rights confirmation is required; original video addresses and identical available source image files are rejected. Arbitrary media IDs cannot be attached or removed. Uploaded video files are not introduced; the existing application supports trusted video links.
- `RecipeVariationAttribution` exposes only authorized historical attribution fields and access-checked current source links. Internal evidence references never enter the editor's public state. Source revision changes show a notice without synchronizing content.

## Member interface

- `MakeRecipeVariation`: accessible confirmation form on eligible source pages, with a protected request key and redirect to the destination editor.
- `EditRecipeVariation`: English/Greek titles, summaries, plain-text introductions, change summaries, times, servings, ingredient search, groups, quantity rules, ordered steps, taxonomy, private media upload/removal, saved preview and a link back to the accessible original.
- `PersonalRecipes`: owner-only collection with all/original/variation filters and variation labels.
- Routes: `/{locale}/my-recipes` and `/{locale}/my-recipes/{recipe}/edit`, behind existing authentication and verification middleware. Private pages use no-store and no-index headers. Language switching preserves the collection/editor route.
- CSS uses the existing palette and typography, a single-column mobile layout, labeled controls, keyboard focus indicators and live status/error announcements.

There is no ordinary-member Filament access and no new authentication system.

## Release switches and boundaries

`RECIPE_VARIATIONS_ENABLED` defaults to false. It can be enabled in an isolated review environment after applying Stage 1 migrations and recording verified source permissions. It has not been enabled in the application environment.

`public_submissions_enabled` remains false. Variation submission, public visibility, direct publication and sharing are rejected by record policies and existing publishing-service entry points, rather than relying on hidden buttons. Stage 4 must replace these temporary restrictions with the frozen first-publication and rights-clearance workflow.

No application database migrations were run during this stage. Existing source content and Stage 1 migrations were preserved. No packages were added.

Shopping lists, private matching, planner/household behavior, dietary claim review, favorites and ratings require the dedicated integration validation in Stage 6. The existing first-publication revision gaps and unverified deployment guard mode remain release blockers.

## Validation

New tests cover fork ownership and independent IDs, source immutability, approved-payload isolation, rights restrictions, media exclusion, quotas, rollback, idempotency, real concurrent processes, private editor authorization, stale saves, forged child IDs, one-language drafts, bilingual rendering, private uploads, media rights, source-media rejection, group/step editing, collection isolation and feature switches.

The asset build uses the installed Vite/Tailwind pipeline. No interactive browser or device accessibility audit has been performed; those remain part of release validation.

### Executed results

- The new fork/editor/concurrency and Stage 1 provenance selection passed: 47 tests, 206 assertions.
- The expanded PostgreSQL regression selection passed: 168 tests, 585 assertions. It included variations, provenance, revisions, quotas, access, sharing, publishing and existing workflow tests. Two existing access tests still report unclosed-output-buffer risky notices; their assertions passed.
- Independent-process tests confirmed that a repeated key creates one variation and competing distinct requests cannot exceed a quota of one.
- Production assets built successfully with `npm run build`.
- Pint and whitespace checks passed.

Validation used the disposable PostgreSQL cluster on port 55432 with database `the_sunday_table_test` and a 512 MB test-process memory limit. The application database and content guard mode were not changed.

Early test runs caught optional ingredient-translation validation, incorrect factory ownership helpers, an overly exact cache-header assertion and incomplete source-category fixtures; these were corrected. The final compatibility check aligns recipe timing limits with the existing editor (up to 10,000 minutes for preparation/cooking/rest and 32,000 minutes per step).

After the timing compatibility adjustment, all 28 fork and editor tests passed again (133 assertions), including the new long-rest/long-step preservation test. The two risky notices in the broader run are `RecipeAccessTest::test_public_recipes_are_visible_to_guests_until_made_private` and `RecipeAccessTest::test_private_recipes_may_have_one_language_and_still_render`.
