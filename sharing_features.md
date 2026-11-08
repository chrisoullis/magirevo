# MASTER IMPLEMENTATION SPECIFICATION
# Recipe Sharing + Weekly Meal Plan Sharing
# Bilingual Laravel Cooking Website — Safe, Resumable Codex Implementation

> **Status:** NOT STARTED — requires Stage 0 reconciliation with the actual repository.
> **Execution:** Codex is authorized to continue through stages after Stage 0 when safe; stop only for a critical blocker, suspected data loss, unclear permission/security policy, or missing prerequisites.
> **Source of truth:** This document's `IMPLEMENTATION CHECKPOINT` section. Update it before ending each work session and after every completed stage.

---

## 1. Objective and user experience

Implement an elegant, bilingual (`en`, `el`), mobile-first **Share Recipe** action and **Share Weekly Meal Plan** action in my existing Laravel cooking website. Make both features easy for nontechnical users and reuse the existing Recipe, Recipe Revision, Sharing, Household, Meal Planner, media, authentication, policies, notifications and email architecture. Never create a second conflicting sharing or permissions system.

### Recipe sharing — two phases

**Phase 1: Public recipe sharing** (implement first):
- One obvious **Share Recipe** action on recipe detail pages, accessible on mobile and desktop.
- Prefer native share sheet via Web Share API (`navigator.share`) when available, initiated directly by user gesture. A user cancel is not an error. Always retain an accessible custom share menu as a fallback and as a discoverable option.
- Custom actions: **WhatsApp, Facebook, Pinterest, Email (`mailto:`), Copy Link**. Optional general 'More apps' native action.
- Produce proper localized, canonical public URLs and accurate Open Graph/Twitter-card metadata for titles, descriptions and authorized cover images. Sharing preview content must come from the currently approved published revision, not a draft. No invented ratings or nutrition data.
- No social-network SDKs or trackers necessary. Use official share endpoints when documented and tested. Never promise that a third-party platform will display a particular preview, or that third-party URL schemes remain available forever.
- **Do not share protected content through public social URLs.** For private/shared recipes, direct public sharing options must be disabled or clearly convey that the recipient cannot see the page. Do not put private titles, descriptions, images or previews in external share URLs or metadata.

**Phase 2: Authenticated and restricted recipe sharing**:
- Reuse existing named-recipient recipe grants and `/en/shared-with-me` functionality. Inspect their policy/permission services and models before adding anything.
- Allow owners/other explicitly permitted users to select a registered member as a recipient and grant the exact scope already supported by the system. No implicit visibility escalation from merely viewing a shared recipe. A recipient must still pass `RecipeAccessService`/`RecipePolicy` when accessing it.
- Optional invitation for an email not yet registered: secure random, single-purpose, expiring, revocable token; bind invitation to intended email/recipient where feasible; no public content exposure; send through Laravel Mail and existing queued delivery. Verify recipient identity before granting access.
- Display whom a recipe is shared with; owner can revoke where current permissions allow; maintain an audit history and provide meaningful notifications/feedback.
- Do not create a second `recipe_shares` table, duplicate notifications workflow or duplicate authorization model.
- Optional share-as-PDF is **a later enhancement**, not a launch blocker; use only authorized visible content, appropriate bilingual fonts and robust access checks, with no public storage of private PDF files.

### Weekly meal plan sharing — husband to wife scenario

A user plans Monday–Sunday meals and wants to **send that exact week's menu to their spouse**. Provide TWO clearly differentiated workflows:

**A. Share snapshot — read-only, recipient need not be a registered member.**
- On the existing Meal Planner weekly view, add a visible **Share Week** button.
- Choose current plan/week and optionally recipient/email; allow user to **preview precisely what the recipient will see**.
- Create a sanitized, **immutable snapshot** of that week rather than an unrestricted live link to the underlying plan. Include week start/end (timezone and locale), day, meal slot/type if used, safe meal display name, servings if user opts in, and permitted public recipe links. Optional brief user-authored note. Avoid exposing internal IDs, meal notes, health/dietary preferences, private substitutions, household members, email addresses or shopping lists by default.
- Offer **Copy Secure Link**, **Email**, **Native Share**, and **Print** (PDF later as optional). Email may transmit a tokenized URL, not embed private content in plaintext unless the user explicitly opts in and policies allow it.
- Use cryptographically strong random tokens. Store **hashes** of bearer tokens, not plaintext tokens, and compare securely. Restrict token scope to a single sanitized immutable snapshot. Include expiry and explicit revocation. Set conservative expiry (e.g., 7 days) as a configurable default. Rate-limit share creation and redemption, log creation/revocation/access (minimal metadata), add `noindex`, `no-store` where appropriate, `Referrer-Policy: no-referrer` and avoid third-party resources on protected link pages. Avoid leaking bearer tokens in redirects, logs, analytics, Referer headers or OG previews.
- **Privacy rule:** For a private/shared recipe, do not reveal the recipe's name or description through a snapshot unless the creator has explicit authority to disclose that information to the intended recipient; by default, redact or label the meal privately and do not provide recipe links. Public published recipes may link normally. The preview must make every redaction obvious. Access to protected source recipes through any link must still require the underlying recipe authorization.
- Expiry/revocation must work even if a screenshot/email was saved externally; clearly tell users that revocation cannot erase already copied data. Do not support permanent public access by default.

**B. Share/collaborate within an existing Household — intended for spouse who has an account.**
- Reuse current household roles (`owner`, `admin`, `member`, `viewer` where currently implemented), invitations and authorization.
- Let an authorized member choose a Household weekly meal plan, invite/notify their spouse using existing membership mechanisms, and allow that spouse to **view or collaborate** according to actual household role and planner policy.
- This is **live** collaboration, distinctly labelled from the **read-only snapshot** above. Do not automatically create household membership or grant access simply because an email address is entered.
- Follow the existing strict rule: a private recipe planned for a household may be shown as recipe content only if the recipient is independently authorized to view it; no automatic recipe sharing from plan membership.
- Distinguish shared plan changes from already generated shopping items: changes to recipes/servings must not silently overwrite checked or manually edited shopping items. Reuse stale/regenerate workflow.
- Add clear UI copy: “Send a read-only copy of this week” versus “Share with household for collaboration.”

## 2. Existing architecture to inspect (Stage 0)

Verify actual code and DB; do not assume implementation:
- Laravel version, PHP, Livewire 4, Alpine.js, Tailwind v4, Vite, Filament, bilingual route and Astrotomic systems.
- `Recipe`, `RecipePolicy`, `RecipeAccessService`, statuses and visibility (`public`, `private`, `shared`), immutable Recipe Revisions / `current_revision_id` and media access.
- Existing recipe named-recipient sharing (`recipe_shares` or equivalent), Shared With Me pages, grants/revocations, audit logs, events and queued notifications.
- Existing recipe detail page / frontend components, language routing, canonical URLs, sitemap, SEO and OG metadata, secure media routes.
- Meal Plan models, entries, week boundaries, individual and household ownership, `planned_recipe_revision_id`, household roles, invitations, planner controllers/Livewire, existing PDF/print features.
- Existing email/Mailtrap development configuration, queue drivers, notification channels and rate-limit conventions. Never print or copy secrets or `.env` contents into the report.
- Existing tests, migrations, applied migration history, git status/diff/untracked changes. Do not overwrite partial implementations.

Stage 0 must return an inventory and gap analysis with exact models, services, components, routes, migrations and tests affected. Mark each feature **already implemented / partial / missing / conflicting**. Verify whether stage-specific changes can be done without data migration. Do not delete or reset existing data.

## 3. Shared backend architecture

Prefer reuse of existing authorization and notification patterns. Extract minimal reusable abstractions only where both recipe and plan sharing need the same functionality (e.g. secure token generation, redacted share previews, expiry/revocation, audit). Avoid a generic over-engineered 'universal share engine'.

Suggested services (adapt names to codebase):
- `RecipeShareService` or existing recipe share service extension (internal grants).
- `MealPlanShareService` (snapshot creation, revoke, expiry and recipient delivery).
- `ShareTokenService` only if required for multiple protected sharing contexts.
- `ShareVisibility/PreviewBuilder` ensuring only authorized public fields appear.

**Security authority:** policy/service checks and database constraints — not hidden buttons. Validate owner/household membership on every mutation. Share-token access to a snapshot does **not** grant access to recipe routes or live planner data.

### Suggested additive schema (only if missing)

`meal_plan_shares`:
- id, plan_id or household/owner reference (based on actual domain model), created_by, week_start_date, week_end_date, `timezone`, `locale`, `snapshot_payload` JSONB, `token_hash` unique, `expires_at`, `revoked_at` nullable, `last_accessed_at` nullable, `access_count` default 0, `created_at`, `updated_at`.
- Optional `recipient_email_hash`/recipient user ID if private recipient binding is implemented. Avoid storing emails without purpose/retention controls.
- Define a snapshot schema version for future compatibility and a retention/cleanup policy. Remove or anonymize expired/revoked snapshots on a documented schedule, while preserving minimal appropriate audit events.
- Index relevant scope/status/expiry columns; do not store plaintext tokens. Any emailed token may exist transiently in queue payloads: protect queue access and retention.

`meal_plan_share_audits` or reuse existing audit infrastructure:
- share_id, actor_id nullable, action, sanitized metadata, timestamp. Avoid logging token or protected recipe names.

Do not add duplicate recipe-sharing schema if a suitable mechanism already exists. Do not add global recipe visibility just to make share features work. Use additive migrations; do not modify applied migrations; no `migrate:fresh`, truncate, or destructive seeding.

## 4. UI/UX

### Share Recipe button
- Place near Save/Favorite, Print and Make It Your Own on public recipe pages. Label in EN **Share Recipe**, EL **Κοινοποίηση συνταγής**; accessible icon and text, not icon-only on primary action.
- Accessible mobile-first bottom sheet/modal; keyboard focus trap/restoration, Escape close, explicit close button, screen-reader labels, error/success feedback.
- Show current public recipe card preview and available actions: Native Share, WhatsApp, Facebook, Pinterest (authorized image/URL), Email, Copy Link; allow registered-user share only where rights and policy permit.
- Use `navigator.share({title, text, url})` with HTTPS/user gesture and catch `AbortError`. Clipboard API with accessible fallback for unsupported contexts; `mailto:` safely encoded. Open external shares only after explicit click, use appropriate `noopener,noreferrer` for new tabs.
- Use existing localized route helpers and public canonical URL resolution, not the Codespaces preview host as a permanent public share URL. During development allow explicit preview/test domains without misrepresenting them as production links.
- Do not use scraped or copyrighted third-party image assets merely to depict a social button. Use approved icons/logos or simple text.

### Share Weekly Plan button
- On weekly planner toolbar near week selector / Print / Shopping List controls. Label EN **Share Week**, EL **Κοινοποίηση εβδομάδας**.
- Sheet/modal: **(1) Preview** → **(2) Sharing method** → **(3) Confirm**. Choose between read-only snapshot or household collaboration. If email path chosen, require recipient address confirmation and clear privacy summary.
- Include clear recipient-facing view optimized for mobile and printing, with week range, days and meal names or privacy-safe placeholders. Translated labels/date formatting. Show expiry and read-only status. No exposed edit links. Empty-plan handling and permission errors.
- Prefer existing Laravel Mail queued delivery; add Mailtrap tests in development only, do not send to real recipients during automated tests.
- Sharing one week must not inadvertently share all weeks, entire household, pantry contents or shopping history.

## 5. Metadata, SEO, and social limits

- Recipe public pages: canonical URL; `og:title`, `og:description`, `og:image`, `og:url`, appropriate image dimensions and fallback; `twitter:card` if existing app supports it; locale tags and `hreflang` consistent with application. Use only valid public revision content and authorized public image file paths.
- Ensure pages with draft/private/shared recipes are not indexed. Share snapshots and token pages: `noindex, nofollow`, no public preview metadata, avoid leaking sensitive names.
- WhatsApp/Facebook/Pinterest share links and preview behavior should be tested against current official integration patterns; do not depend on undocumented APIs or custom social SDKs. Pinterest pin creation may require a publicly accessible image and may be unavailable for restricted content; omit/disable as appropriate.
- Native sharing and clipboard are progressive enhancements. Manual copy and direct accessible actions must remain usable.

## 6. Privacy, licensing and copyright

- Public recipes: permitted public URL sharing, while republishing or distributing full images/text is a separate rights question.
- Shared/private recipes: a copied URL is never a new permission grant. Recipients must have valid existing rights. Internal grants require owner/admin authorizations.
- No external social share previews, emails, downloadable assets or snapshot links may expose private recipe text/media by default.
- User-generated recipe variation rights/provenance (`Make It Your Own`) remain respected. Do not bypass content reuse restrictions.
- Household meal-plan access does not grant private recipe access automatically.
- Rate-limit creation and access, protect against enumeration, XSS, IDOR and open redirects, guard token leaks, test revocation and retention.
- Never use sharing actions to make unapproved dietary claims public.
- Legal/privacy and retention policies should be reviewed before production rollout, without presenting automated tests as evidence of legal compliance.

## 7. Phases and implementation stages

**Stage 0 — Reconnaissance (no code changes).** Inspect repository, git diff, applied migrations, existing sharing, meal plan and households, services, UI, policies, tests and proposed safe architecture. Write Stage 0 report in checkpoint; continue automatically only if no critical ambiguity or risk. Avoid speculating about progress.

**Stage 1 — Shared UX foundations + public recipe share.** Implement accessible Share Recipe button/modal, native API fallback, WhatsApp/Facebook/Pinterest/mailto/copy URL options, public canonical URL and OG metadata, bilingual labels and relevant tests. Preserve existing recipe guards. This delivers Phase 1.

**Stage 2 — Existing internal recipe sharing integration.** Integrate frontend action with named-recipient grants, policy checks, notification/revocation UI and Shared With Me. Add secure invitations only when absent and needed; design/test tokens and recipient identity. This delivers Phase 2 for recipes.

**Stage 3 — Meal-plan snapshot schema and services.** Add only necessary additive migrations and snapshot generation, redaction, token hashing, expiry, revocation, retention, auditing, email integration. Test private recipe redaction and token scope.

**Stage 4 — Share Week public-facing UI.** Add share action to planner, preview, native/copy/email/print options, read-only recipient page, EN/EL localization, mobile accessibility, secure testing. Confirm expired/revoked link behavior.

**Stage 5 — Household collaboration.** Reuse existing household invitations/roles and planner, clarify editable live plan vs read-only snapshot, verify privacy and shopping-list regeneration consistency. Do not duplicate household sharing.

**Stage 6 — Regression/security/performance and rollout.** Complete Laravel/Livewire/PostgreSQL tests, authorization/rate-limit/token/expiry/revocation tests, social link encoding, browser fallback, EN/EL route parity, accessibility and no private-data leakage. Investigate baseline test failures separately; don't silently disable. Keep advanced PDF export optional or propose as future stage.

At the end of **each stage**: update `IMPLEMENTATION CHECKPOINT` below, listing changes, migrations and test evidence. Commit only if normal project workflow permits; never overwrite user's uncommitted work. A stage may be marked partial with exact remaining steps.

## 8. Codex usage budget and safe stopping

- **Stop when verified remaining Codex usage is 10% or lower.** Do not confuse this with 10% consumed (the intended stop threshold is **10% remaining**).
- Read a genuine available usage signal (for example `/status` output when supplied by the user or a supported CLI usage display). Do not invent percentages, parse unrelated token counts as account quota, or claim continuous autonomous monitoring if not supported.
- If automatic usage inspection is unavailable, explicitly mark `usage_remaining: unknown` and checkpoint after each stage and after any substantial migration/refactor. Continue only within the user's requested scope; ask the user to check `/status` manually if needed.
- If user reports ≤10% remaining at any time, stop at the **next safe boundary**: finish or roll back the in-flight transaction, ensure code/data consistent, save checkpoint and summarize what remains. Do not begin a new stage. Avoid leaving migrations half-applied or security checks half-enabled.
- Keep sufficient usage to write a meaningful checkpoint; when nearing limit, favor safe state + clear notes over beginning another complex task.
- The Markdown file itself **cannot monitor Codex usage or cause a guaranteed automated stop**; these are operational instructions the agent must follow when usage data is available.

## 9. Implementation checkpoint — MUST KEEP CURRENT

Update this section IN PLACE after Stage 0, each stage and before ending work. Do not delete completed history: add concise entries under Stage log. All paths must be real inspected paths. Never record API keys, tokens, passwords, private email addresses or other secrets.

```yaml
project: Recipe Sharing + Weekly Meal Plan Sharing
spec_file: sharing_features.md
implementation_status: not_started
current_stage: 0
last_completed_stage: null
stage_status: pending
usage_remaining_percent: unknown
last_verified_usage_source: none
last_checkpoint_at: not_set
working_branch: unknown
migration_status: not_inspected
uncommitted_work_present: unknown
last_tests_run: not_run
current_blockers: []
next_safe_action: "Audit current repository and DB; reconcile existing sharing implementations."
```

### Stage log

| Stage | State | Files/migrations changed | Tests and results | Remaining tasks / blockers |
|---|---|---|---|---|
| 0 | Pending | — | — | Inspect repository and report safe plan |
| 1 | Pending | — | — | Public recipe share |
| 2 | Pending | — | — | Internal recipe grants/invitations |
| 3 | Pending | — | — | Weekly plan snapshot backend |
| 4 | Pending | — | — | Weekly plan sharing UI |
| 5 | Pending | — | — | Household collaboration integration |
| 6 | Pending | — | — | Final validation |

### Work completed (append dated concise notes)

- Not started.

### Modified files / migrations (fill with real paths only)

- None yet.

### Tests: commands / pass / failures / not run

- Not run.

### Outstanding risks and next steps

- Confirm actual current recipe sharing / household models and permissions before schema work.
- Identify any existing share link/token implementation to avoid duplication.

## 10. Resuming in a new Codex session

Use this exact instruction:

> Read `sharing_features.md` fully, including `IMPLEMENTATION CHECKPOINT`. Compare the checkpoint with git status, actual files, migrations and tests, and resume from the next unfinished safe step. Do not assume the previous agent completed anything not verifiable. Do not reset or recreate existing data. Update the checkpoint after each stage. Stop at the next safe boundary when verified Codex usage reaches 10% remaining.

## 11. Acceptance criteria

- A public recipe can be shared through native share and accessible WhatsApp/Facebook/Pinterest/Email/Copy Link fallbacks, with localized URLs and accurate public preview metadata.
- No social/public share action grants unauthorized recipe access or leaks private content.
- Existing registered-user sharing, revocation and Shared With Me work, without duplicate permission data.
- An owner can share **one chosen week** as an expiring, revocable, privacy-safe **read-only snapshot**, via secure link/email/copy/native share.
- A spouse with a household account can receive a household invitation or use established membership to view/collaborate on the **live shared weekly meal plan** according to their role.
- Private recipe details remain protected in links, emails, OG tags, snapshots, PDF/print and household displays.
- Existing Meal Planner, Shopping Lists, Households, Make It Your Own and Recipe Revisions are not broken.
- EN/EL strings, responsive accessible UI, authorization, security, tests and migration safety are verified.
- Stage log/checkpoint is updated so work is safely resumable.

---

## 12. FIRST ACTION FOR CODEX

**Begin now with Stage 0, then progress stage-by-stage automatically if safe.** Do not pause for routine approval between stages unless an architectural conflict, permission-policy ambiguity, security/privacy risk, destructive migration risk or another critical blocker requires user input. This is the user's explicit authorization for staged implementation, not permission to expose private data or bypass tests.

Before changing anything, audit current implementation and uncommitted work. Use the smallest safe changes. Update the checkpoint after each stage. At verified ≤10% Codex usage remaining, stop safely and document exact state for continuation.
