# The Sunday Table

A seasonal recipe website built with Laravel 13, Livewire 4, Filament 5, and PostgreSQL. Visitors can browse and search recipes without an account. Members can register, verify their email, log in, and keep a personal recipe box. Editors manage recipes through Filament at `/admin`.

## Requirements

- PHP 8.3 or newer with `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `hash`, `intl`, `mbstring`, `openssl`, `pdo_pgsql`, `session`, `tokenizer`, `xml`, and `zip` extensions
- Composer 2.8 or newer
- Node.js 20.19+ or 22.12+ and npm
- PostgreSQL 14 or newer
- A mail provider for sending verification links in production

Filament requires the PHP `intl` and `zip` extensions. Laravel's PostgreSQL connection requires `pdo_pgsql`. Install these extensions for both CLI PHP and the PHP web runtime; do not bypass Composer platform checks in a deployed environment.

## Setup

1. Install PHP and PostgreSQL, then create an application database and user. For example, from `psql` as a database administrator:

	```sql
	CREATE USER sunday_table WITH PASSWORD 'replace-with-a-strong-password';
	CREATE DATABASE the_sunday_table OWNER sunday_table;
	```

2. Install PHP dependencies and create the local environment file:

	```sh
	composer install
	cp .env.example .env
	php artisan key:generate
	```

3. Edit `.env` and set `APP_URL`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` for your PostgreSQL server. The example file uses PostgreSQL by default.

4. Configure email. The default `MAIL_MAILER=log` is for local development; verification messages are written to `storage/logs/laravel.log`. For real email verification, configure your SMTP provider in `.env`, for example:

	```dotenv
	MAIL_MAILER=smtp
	MAIL_HOST=smtp.example.com
	MAIL_PORT=587
	MAIL_USERNAME=your-mail-user
	MAIL_PASSWORD=your-mail-password
	MAIL_SCHEME=tls
	MAIL_FROM_ADDRESS=recipes@example.com
	MAIL_FROM_NAME="The Sunday Table"
	```

5. To seed the first administrator, set these values in `.env` before running the seeder. The seeded account is marked as verified and can access Filament.

	```dotenv
	ADMIN_NAME="Kitchen editor"
	ADMIN_EMAIL=editor@example.com
	ADMIN_PASSWORD=use-a-unique-strong-password
	```

6. Apply migrations, load the starter recipes and optional administrator, and install frontend dependencies:

	```sh
	php artisan migrate --seed
	npm install
	npm run build
	```

7. Start the website:

	```sh
	php artisan serve --host=0.0.0.0 --port=8000
	```

	Open `http://localhost:8000`. The editor sign-in is at `http://localhost:8000/admin`.

8. Publish uploaded media. Recipe images uploaded in Filament are stored on the `public` disk, so the public symlink must exist or images will not show on the site:

	```sh
	php artisan storage:link
	```

For live frontend development, run `npm run dev` in a second terminal and keep it open while using the Laravel server.

### Making an existing user an administrator

The seeder (step 5) is the easiest route. To promote a user who already exists:

```sh
php artisan tinker --execute 'App\Models\User::where("email", "you@example.com")->first()->forceFill(["is_admin" => true, "email_verified_at" => now()])->save();'
```

Access to `/admin` requires both `is_admin=true` and a verified email. `/admin` is registered by the Filament panel provider (`app/Providers/Filament/AdminPanelProvider.php`), so it does not appear in `routes/web.php`; use `php artisan route:list --path=admin` to see it.

### PHP extensions on a custom PHP build

If the admin shows `The "intl" PHP extension is required`, `intl` (and `zip`) is not enabled for the PHP binary running `artisan serve`. Install or enable it, then restart the server. Check with `php -m | grep -E 'intl|zip'`.

## Admin Panel

The Filament panel at `/admin` has a **Recipes** menu and a **Recipe data** group with one menu link per lookup table. Every lookup has English and Greek tabs for its translated fields, an active toggle and a sort order.

| Menu | Manages |
| --- | --- |
| Recipes | Recipes, translations, ingredients, steps, media, publishing |
| Categories | Recipe categories (`code`, names, localized slugs) |
| Tags | Recipe tags (`code`, names, localized slugs) |
| Difficulties | Difficulty levels offered on recipes (`Easy`, `Medium`, `A little project`) |
| Ingredients | Ingredient catalogue with plural names and a default unit |
| Units | Measurement units, type (mass/volume/count), base conversion and abbreviations |
| Scaling modes | How an ingredient line behaves when servings change (`linear`, `fixed`, `manual`) |

Notes:

- Recipe **introductions** use the Filament rich editor (bold, italic, lists, links, headings). The stored HTML is sanitised before it is rendered on the public recipe page; older plain-text introductions still display as paragraphs.
- Difficulties and scaling modes used to be hard-coded. They are now tables (`difficulties`, `scaling_modes`, with translation tables), and `recipes.difficulty` / `recipe_ingredients.scaling_mode` reference their `code` through foreign keys. The recipe form lists only active entries, plus the value a recipe already uses.
- A lookup record that is used by a recipe (a category, tag, difficulty, ingredient, unit or scaling mode) cannot be deleted; deactivate it instead. The delete action shows a notification explaining why.
- A lookup `code` cannot be changed for difficulties and scaling modes after creation.
- Only the `linear` scaling mode is scaled automatically by the serving calculator; other modes keep the written amount.

### Seeders

`php artisan db:seed` is idempotent and runs, in order: `UnitSeeder`, `CategorySeeder`, `TagSeeder`, `DifficultySeeder`, `ScalingModeSeeder`, `IngredientSeeder`, `RecipeSeeder`, `RecipeContentSeeder`, plus the optional administrator from `.env`. Run an individual one with `php artisan db:seed --class=DifficultySeeder`.

## Using the Site

- `/` redirects to `/en`; `/en` and `/el` list published recipes. Search and category filters update with Livewire.
- `/en/recipes/{slug}` and `/el/recipes/{slug}` show the recipe in the selected locale, with matching localized slugs.
- `/register` creates a member account and sends a signed email-verification link.
- `/login` signs in an existing member. Unverified accounts are sent to the verification prompt before using member-only features.
- `/en/saved-recipes` and `/el/saved-recipes`, plus recipe saving, are available only to signed-in, verified members.
- `/admin` is the Filament recipe editor. Only verified users with `is_admin=true` can access the panel.
- `/sitemap.xml` lists both home pages and both locale URLs for each published recipe.

Recipe photos are served from Unsplash URLs. Editors manage English and Greek titles, slugs, summaries, introductions, and SEO metadata in Filament. A recipe can be published only when both locales have a title and slug, and it appears publicly after it has a publication date. Other recipe data, including category, ingredients, and instructions, remains shared between locales.

Existing recipe copy is migrated as English and set back to draft until a Greek translation is added. The bundled starter recipes include both translations and are published when the database is seeded.

## Tests

Run the feature suite with:

```sh
php artisan test
```

Tests use the PostgreSQL database `the_sunday_table_test`, using the connection credentials from `.env`, and cover bilingual recipe discovery, locale-specific URLs, publication requirements, translation cleanup, registration, email verification, and verified-only recipe saving, and the Filament admin resources (including the lookup CRUDs and their delete protection). Filament page tests are skipped when the `intl` extension is missing. Create this test database with the same owner as the application database before running `php artisan test`.

## Production Notes

- Set `APP_ENV=production`, `APP_DEBUG=false`, a correct `APP_URL`, and a strong `APP_KEY`.
- Configure production PostgreSQL credentials, SMTP, HTTPS, and trusted proxy settings for your host.
- Build assets with `npm ci && npm run build`; serve the Laravel `public/` directory through a production web server.
- Run `php artisan migrate --force` during deployment. Keep `ADMIN_PASSWORD` out of source control and clear it after the first seeded administrator is created.
- Use Laravel's scheduler/queue worker if later features dispatch queued work; verification mail currently sends through the configured mail transport.
- The site emits canonical URLs, Open Graph metadata, responsive viewport markup, Recipe structured data, and a dynamic XML sitemap. Add the deployed sitemap URL to your search-console account after launch.

## Stack

- Laravel 13
- Livewire 4
- Filament 5
- PostgreSQL
- Vite
