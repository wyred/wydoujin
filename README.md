# wydoujin

A self-hosted, **single-user** reading server for a doujin/manga library (Kavita-like). Files live
on disk as `/library/<mangaka>/<doujin>.zip`. wydoujin scans them into a database, parses metadata
from filenames into normalized tags, groups works into series, and serves a web reader — pages stream
straight from the zip; only resized covers are cached.

**Stack:** Laravel 13 (PHP 8.3+) · Blade + Tailwind CSS · Alpine.js · Vite · FrankenPHP · single
Docker image (web + queue worker + scheduler under s6-overlay) · MySQL in production, SQLite for
local dev and tests.

> See [`CLAUDE.md`](CLAUDE.md) for architecture, invariants, and where things live.

## Local development

> **Toolchain quirk on this dev machine:** the default `php` is broken — prefix `php`/`artisan`/
> `composer` commands with `PATH="/opt/homebrew/opt/php/bin:$PATH"` (PHP 8.5). Node/npm are on the
> normal PATH. Inside Docker, `php` is fine.

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate   # local dev uses a SQLite file
npm run build            # or: npm run dev  (Vite HMR)
php artisan serve        # plus: php artisan queue:work   (scan + cover-gen jobs)
```

### Tests

```bash
php artisan test                                   # full suite (Pest 4, in-memory SQLite)
vendor/bin/pest --filter=health                    # a single test / group
php -d pcov.enabled=1 vendor/bin/pest --coverage   # coverage (PCOV is loaded but OFF by default)
vendor/bin/pest tests/Browser                      # Playwright browser suite (explicit; not in CI)
npm install && npx playwright install chromium     # one-time prereq for the browser suite
```

CI (`.github/workflows/ci.yml`) runs `php artisan test` on in-memory SQLite. `build.yml` pushes the
Docker image to GHCR on push to `main` and on `v*` tags.

## Running with Docker

```bash
docker compose up -d
docker compose exec app php artisan migrate --force
```

Key environment variables (set in `.env`):

| Variable | Purpose |
| --- | --- |
| `APP_KEY` | Laravel app key (`php artisan key:generate`). |
| `APP_PASSWORD` | Single-user gate. **Unset → the app is open**; set → one password guards everything except `/health` and `/login`. |
| `LIBRARY_PATH` | Host path to your `<mangaka>/<doujin>.zip` library (mounted read-only). |
| `DATA_PATH` | Writable data root (cached covers + Laravel storage). |
| `DB_PASSWORD` | **Required** — `docker compose up` fails fast if unset (no insecure default). |
| `DB_ROOT_PASSWORD` | Required when using the bundled MySQL service. |

### External MySQL

To use your own MySQL server instead of the bundled one: remove the `mysql` service from
`docker-compose.yml` **and** the `app` service's `depends_on: mysql` entry, then point
`DB_HOST`/`DB_PORT`/`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD` at your server. Both edits are required —
leaving `depends_on` while the `mysql` service is absent makes `docker compose up` fail.
