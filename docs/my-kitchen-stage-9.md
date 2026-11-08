# My Kitchen — Stage 9 validation

Validation date: 2026-11-03. This report covers the accumulated Stage 1–8 working tree, plus the fixes below. Existing work was preserved. Tests were not disabled or weakened.

## Fixes made during validation

1. **Asynchronous public search suggestions could remain hidden.** The Alpine visibility expression depended on DOM contents that changed during a Livewire update without triggering the expression again. Visibility now depends on the reactive open state and a server-rendered content class. The combobox expanded state also reflects the server result count. A browser regression in `tests/browser/public-search.cjs` verifies that results and empty-result messages appear without further typing and that results can be selected with Arrow Down and Enter in both languages.
2. **Language-switcher accessible names did not match the visible labels.** Removed overriding `aria-label` values and appended screen-reader text to the visible EN/EL text, so the accessible name includes the visible label. This addresses WCAG 2.5.3.
3. **Kitchen module shortcuts advertised denied/disabled destinations.** Replaced their independent link list with the existing `PublicNavigation` service, retaining household planner/shopping context. English/Greek role-less-user regressions verify that unavailable sharing, recipe, planner and household destinations are hidden. The final navigation suite passed **14 tests, 72 assertions**. Server authorization was unchanged.
4. **Shared editor ordinary-tag sync removed protected associations.** The initial full run completed with 904/905 tests passing; the unchanged `RecipeTagSeederTest` identified removal of an existing dietary pivot when saving an ordinary tag in Filament. The Stage 1 shared editor had replaced a scoped relationship field with an unrestricted sync. The shared data mapper now exposes only active editorial tags to that field, and shared draft/revision persistence preserves existing dietary, unclassified and inactive associations. Domain validation still rejects newly submitted dietary IDs, and no claims or approvals are created. Added draft/revision regressions for preservation and rejection. The initial failing result is retained in `validation/stage-9/full-tests-before-fix.json`.

## Test and build results

**Final complete PHPUnit collection: 911 passed, 4,325 assertions, zero failures/errors/skips.** This includes Laravel/PostgreSQL feature and integration tests, Livewire and Filament tests, editor parity, revisions and concurrency, authorization/quotas, bilingual routes/search, shopping, planning and variation workflows. All six added navigation/protected-tag regression cases are included in this final collection. No tests were disabled and no baseline assertion failures remain unresolved. See `validation/stage-9/full-tests.json`.

The ordinary `php artisan test --compact` attempt stopped prematurely during `CategoryTaxonomyTest::test_admin_list_shows_hierarchy_and_counts_and_runs_scoped_actions` under PHP's default 128 MB memory setting (the runner did not report a definitive cause). A direct run with a 1 GB allowance was used for diagnostics and later restarted with Xdebug disabled and isolated storage; this changes the runner environment rather than application behavior or test assertions.

The independently isolated redesign suites passed: **81 tests, 457 assertions**, including the frontend studio, Filament/editor parity, planning integration, discovery, homepage, dashboard, navigation, search and ingredient browsing. An earlier concurrent attempt produced one media-storage assertion failure because Laravel fake disks were shared between processes; repeating all 81 suites with both database and storage isolated passed without changing assertions. The final full run also uses its own database/storage and disables Xdebug.

The corrected tag/editor/studio subset also passed **41 tests, 265 assertions**. The four new protected-tag cases passed **13 assertions**, and are included again in the final complete run. Subset counts overlap with the full run and must not be added to it.

Other checks:

- Production Vite build: passed after all presentation fixes.
- PHP syntax: all 87 changed/new non-Blade PHP files passed.
- `vendor/bin/pint --dirty --test`: passed.
- Blade compilation (`php artisan view:cache`): passed.
- `git diff --check`: passed.
- Existing cooking-state JavaScript test file: passed.
- English/Greek translation-key parity: all 20 language files matched.
- New asynchronous-search browser regression: English and Greek passed.
- Browser interaction checks: **14 passed**, covering keyboard suggestions, ingredient checklist persistence, Livewire serving increases, cooking-mode focus/navigation/Escape, mobile navigation, and homepage widths of 320 and 768 pixels in both languages.
- Populated variation editor: **4 browser checks passed**, covering both languages and widths with no axe violations or overflow.
- Populated personal/household planner and shopping browser checks: **16 passed**, including live checklist progress updates, no overflow and no axe violations in English/Greek at both widths.
- SEO/browser assertions: **13 passed**, covering document language, canonical URLs, English/Greek hreflang, parseable structured data on home/detail pages, public sitemap destinations and guest redirects from protected routes.

## Browser accessibility and responsive checks

Chromium, Playwright and axe were installed as temporary audit tools outside the application dependencies. Browser fixtures were seeded into a separate `my_kitchen_audit_test` database. Existing application data was not reset or modified. Variations were enabled only in the isolated audit server for the final conditional-feature checks; application/deployment feature flags were not changed.

The sweep covers 56 combinations: 14 page types × 2 languages × 2 widths (390 and 1440 pixels). It includes home, ingredient index/detail, recipe detail, What Can I Cook?, dashboard, saved/shared/personal recipes, planner, shopping, household index/detail and contributor recipe creation. The audit includes WCAG 2 A/AA, WCAG 2.1 A/AA and WCAG 2.2 A/AA tags. The final results are in `validation/stage-9/browser-audit.json`: **zero axe violations across all 56 combinations**.

All sampled pages returned HTTP 200, with no JavaScript page errors or horizontal overflow. Authenticated page samples included `noindex` directives. Screenshots of the homepage and Greek contributor studio were visually reviewed. These automated and visual checks do not establish full WCAG conformance: manual screen-reader review, broader content variation and real-device testing remain necessary.

## Performance and SEO

The final local mobile Lighthouse run with Xdebug disabled scored performance **81**, accessibility **100**, best practices **100** and SEO **100**. It measured LCP **3.8 s**, CLS **0.003** and total blocking time **280 ms**. The initial Xdebug-enabled run scored 51 with LCP 5.3 s and TBT 940 ms. LCP does not meet the requested 2.5 s target; total blocking time is a lab metric and is not an INP measurement.

Both runs used built assets on an artisan server while the test suite shared the machine; the final run disabled Xdebug on the audit server. It is diagnostic, not a production benchmark. Large seeded remote recipe images, uncached/uncompressed local asset responses and slow origin response time were observed. Required follow-up: responsive recipe image variants, production compression/cache headers, origin/query profiling, and a repeat mobile test on representative hosting. Field INP and production Core Web Vitals have not been established.

Rendered checks confirmed WebSite data on homepages, Recipe/BreadcrumbList data on recipe pages and DefinedTerm/BreadcrumbList data on ingredient detail pages. Sitemap checks and existing public-query scopes exclude private routes/content. No ratings, reviews or nutrition values were fabricated. Ingredient index pages have metadata and hreflang; they do not require detail-page structured data.

## Existing content/product gaps

These are separate from newly introduced regressions:

- The fresh catalogue contains **760 stable ingredient codes and 16 groups**, with English/Greek names and slugs for every ingredient. All 760 lack public primary photos and educational description/use/preparation/storage fields in the seed. The application renders placeholders/pending-content messages; no duplicate entries or unsourced photos were added. This is a seeded-catalogue result, not an audit of production editorial content. See `validation/stage-9/ingredient-catalogue.json`.
- Login, registration and email-verification pages still contain hard-coded English copy on existing global authentication routes. This is reproducible in the original Git HEAD templates; the redesign changed branding but did not localize these flows. Full bilingual product acceptance therefore remains incomplete.
- Production CWV thresholds and manual accessibility acceptance remain open as described above.

## Reproduction and evidence

- `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --debug --log-junit /tmp/my-kitchen-stage9-final.xml` (with dedicated `DB_DATABASE`, `LARAVEL_STORAGE_PATH` and `VIEW_COMPILED_PATH` during this audit)
- `npm run build`
- `node --test tests/js/cooking-state.test.js`
- `vendor/bin/pint --dirty --test`
- `php artisan view:cache`
- `git diff --check`
- See `tests/browser/README.md` for the search regression; it requires a separately seeded audit site and temporary Playwright installation.
- JSON evidence is stored in `docs/validation/stage-9/`.

This validation does not claim production readiness while the documented content, localization, performance and manual-review items remain open.

## Application-database follow-up: My Recipes schema mismatch

After validation, the running `magirevo` database was found to have four pending migrations (`2026_11_02_180000`, `180100`, `180200` and `190000`). The missing provenance migration caused My Recipes to query a nonexistent `recipes.derived_from_recipe_id` column. Fresh test databases had included these migrations, so the original isolated validation did not detect this deployment-state mismatch.

Backed up `magirevo` to `/tmp/my-kitchen-schema-repair-32607a46/magirevo-before-migrations.dump`, then applied the existing pending migrations using `php artisan migrate --force`. No application-query workaround, database reset or reseeding was used. All migrations are now applied. Verified the affected account's My Recipes pages return HTTP 200 in English and Greek, and the original filtering query succeeds. Row counts remained unchanged: 8 recipes, 2 users, 6 media records and 0 derivations.

For future application-instance acceptance, verify `php artisan migrate:status` against that instance's configured database in addition to running fresh-database tests. Passing the isolated suite does not confirm the running instance has applied migrations. This backup is in temporary storage; copy it to durable private backup storage if retention is required.
