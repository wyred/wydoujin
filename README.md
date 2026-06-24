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
| `QUEUE_WORKERS` | Number of in-container background workers (library scans + cover generation). Default `1`, range `1`–`4`. |
| `DB_PASSWORD` | **Required** — `docker compose up` fails fast if unset (no insecure default). |
| `DB_ROOT_PASSWORD` | Required when using the bundled MySQL service. |

### External MySQL

To use your own MySQL server instead of the bundled one: remove the `mysql` service from
`docker-compose.yml` **and** the `app` service's `depends_on: mysql` entry, then point
`DB_HOST`/`DB_PORT`/`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD` at your server. Both edits are required —
leaving `depends_on` while the `mysql` service is absent makes `docker compose up` fail.

## Homelab deployment (SMB library + worker scaling)

A ready-to-edit [`docker-compose.example.yml`](docker-compose.example.yml) is included. It pulls the
prebuilt image from GHCR (`ghcr.io/wyred/wydoujin`) and mounts your library from an SMB/CIFS share:

```bash
cp docker-compose.example.yml docker-compose.yml
docker compose run --rm app php artisan key:generate --show   # → paste into .env as APP_KEY
docker compose up -d
docker compose exec app php artisan migrate --force           # first run only
# open http://<host>:8080  (front it with a reverse proxy for TLS)
```

Example `.env` (next to the compose file):

```dotenv
APP_KEY=base64:...                  # from `key:generate --show`
APP_URL=https://manga.example.lan   # external URL when behind a reverse proxy
APP_PASSWORD=changeme               # single-user gate (omit to leave the app open)

# Manga library over SMB — the share + subfolder holding <mangaka>/*.zip
SMB_HOST=192.168.1.10
SMB_PATH=media/manga
SMB_USER=reader
SMB_PASS=secret

# Database (bundled MySQL service)
DB_PASSWORD=app-db-password
DB_ROOT_PASSWORD=root-db-password

# Background workers (scans + cover generation): default 1, range 1–4
QUEUE_WORKERS=2
```

### Manga library on an SMB share

The example defines a Docker **CIFS volume**, so Compose mounts the share for you — no host `fstab`
required. Point `SMB_PATH` at the **share + subfolder** that holds your `<mangaka>/*.zip`
(e.g. `media/manga`); CIFS mounts that subdirectory directly. The mount is read-only, and
`iocharset=utf8` preserves Japanese filenames. If your NAS needs a different SMB protocol, edit
`vers=3.1.1` in the volume's `driver_opts` (try `3.0` or `2.1` on older servers).

Prefer to mount SMB on the host yourself (e.g. via `/etc/fstab`)? Delete the `manga:` volume from
the example and bind-mount the subfolder instead:

```yaml
    volumes:
      - /mnt/nas/manga:/library:ro
```

### Adjusting the number of queue workers

Background jobs — library scans and cover generation — run on queue workers **inside the single
container**. Set **`QUEUE_WORKERS`** (default `1`, max `4`) and re-apply:

```bash
# .env
QUEUE_WORKERS=3

docker compose up -d        # recreates the container with 3 workers
```

s6 then supervises that many `queue:work` processes (the image bakes 4 worker slots; unused ones stay
idle). Jobs use the database queue with row locking, so workers never double-process a job. **One
worker is plenty for everyday use** — raise it mainly to speed up the first cover-generation pass over
a large library. (To go beyond 4, add more `worker*` slots under `docker/s6/s6-rc.d/` and rebuild.)
