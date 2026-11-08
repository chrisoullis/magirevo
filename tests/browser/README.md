# Public search browser regression

This browser check covers the asynchronous Livewire/Alpine suggestion regression found during Stage 9. It needs the existing bilingual demo recipe seed and an isolated running application. It does not create or modify recipe content.

Install Playwright outside the application dependencies, then run:

```sh
npm install --prefix /tmp/my-kitchen-audit --no-package-lock playwright
/tmp/my-kitchen-audit/node_modules/.bin/playwright install chromium
NODE_PATH=/tmp/my-kitchen-audit/node_modules AUDIT_BASE_URL=http://127.0.0.1:8019 node tests/browser/public-search.cjs
```

Chromium also requires its system libraries (`playwright install-deps chromium` on a disposable Linux development environment). Do not point fixture seeding or database reset commands at a production database.

For English and Greek, the check types a query, waits for the returned suggestions to become visible without further typing, checks the combobox expanded state and empty-result message, and selects a result with Arrow Down and Enter.

## Parallel cooking release check

`cooking-mode.cjs` exercises the real built overlay in Chromium with Laravel-rendered EN/EL fixtures at mobile and desktop widths. Its HTTP responses are intercepted from exported fixtures; it does not replace the PostgreSQL authorization/session tests. Fixture creation runs inside `DatabaseTransactions` and leaves no recipe/session records behind.

First verify that `the_sunday_table_test` already has every migration applied. The opt-in bootstrap below skips automatic database refreshes while retaining test transactions. Do not use it with `RecipeRevisionConcurrencyTest`, `RecipeVariationPublicationConcurrencyTest`, or `RecipeForkConcurrencyTest`: those classes explicitly reset their database in teardown.

```sh
DB_DATABASE=the_sunday_table_test php artisan migrate:status
npm run build
XDEBUG_MODE=off APP_URL=http://cooking.test ASSET_URL=http://cooking.test \
  COOKING_BROWSER_FIXTURES_DIR=/tmp/cooking-stage7-fixtures \
  vendor/bin/phpunit --bootstrap=tests/Support/PreserveDatabaseBootstrap.php \
  tests/Feature/CookingModeIntegrationTest.php
npm install --prefix /tmp/cooking-stage7-browser --no-package-lock playwright
/tmp/cooking-stage7-browser/node_modules/.bin/playwright install chromium
NODE_PATH=/tmp/cooking-stage7-browser/node_modules node tests/browser/cooking-mode.cjs
```

The check covers independent group navigation, waiting/completed/reopened steps, simultaneous timers, pause/expiry/refresh recovery, ingredient checks, bilingual media metadata without autoplay, sound opt-in, modal keyboard focus, horizontal overflow, reduced motion, and the UI response to denied access on a simulated persisted `pageshow`. Screenshots are saved beside the fixtures. It does not verify actual bfcache restoration, real audio/video playback, assistive-technology speech, or simultaneous server workers; those remain release checks in `cooking_steps.md`.

## Live authentication and concurrent release checks

`CookingSessionConcurrencyTest` creates a unique `cooking_release_<16 hex digits>` schema in the existing `_test` database, runs forward migrations there and races independent PHP workers against committed fixtures. It compares existing public-schema table fingerprints before/after each test. It never resets an existing schema; the new scratch schema is retained for inspection.

```sh
XDEBUG_MODE=off COOKING_LIVE_MANIFEST=/tmp/cooking-live-manifest.json \
  vendor/bin/phpunit --bootstrap=tests/Support/PreserveDatabaseBootstrap.php \
  tests/Feature/CookingSessionConcurrencyTest.php
```

For the live browser check, read the schema name from that manifest and start the **loopback-only** runner in a separate terminal. Replace the schema placeholder with the exported value:

```sh
APP_ENV=testing DB_DATABASE=the_sunday_table_test \
  COOKING_RELEASE_SCHEMA=cooking_release_<from-manifest> \
  APP_URL=http://127.0.0.1:8027 ASSET_URL=http://127.0.0.1:8027 \
  SESSION_DRIVER=file SESSION_SECURE_COOKIE=false CACHE_STORE=array \
  QUEUE_CONNECTION=sync MAIL_MAILER=array \
  php -S 127.0.0.1:8027 -t public tests/browser/cooking-live-server.php
```

This runner rejects non-test databases/schema names and keeps normal login, CSRF and authorization checks. The generated account is a factory fixture with password `password`; it exists only in the scratch namespace. Do not expose this runner publicly.

```sh
npm install --prefix /tmp/cooking-stage7-browser --no-package-lock playwright @axe-core/playwright
NODE_PATH=/tmp/cooking-stage7-browser/node_modules node tests/browser/cooking-live.cjs
```

The live check uses two independent browser contexts with real login cookies, session endpoints and version conflicts. It verifies cross-browser resume, timers/checklist persistence, refresh/history restoration, ID-only browser storage, EN/EL accessibility trees, Axe WCAG A/AA findings, Add group and recipe component selection in both contributor editors, and shared child cooking contexts that preserve parent progress/timers across browsers and refresh. Every run starts a new scratch session for repeatability. The output distinguishes actual history navigation from a successful persisted bfcache restore; Chromium may reload instead. Axe/accessibility-tree checks do not establish actual screen-reader speech or human audio/video playback quality.
