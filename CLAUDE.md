# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project status: built (F1–F4 complete, on `main`)

The app is built and merged. The full loop works end-to-end: **scan → browse/search → read →
maintain → organize/tag.** Implemented:
- **Backend:** filename parser, archive (zip) inspection, library scanner + daily scheduled scan,
  series auto-detection, **normalized metadata tags** (via `WorkTagSync`), page/cover serving,
  reading-progress.
- **Frontend:** F1 browse (home, mangaka, series, work detail) · F2 immersive Alpine reader ·
  F3a search + faceted filters (`/browse`) · F3b scan & maintenance (`/maintenance`) · F3c manual
  series management (merge/split/rename) · F4 multi-value tags (per-work tag editor + `/tags`
  global rename/merge; faceting over a normalized tag model).

Work is **document-driven**, following brainstorm → spec → plan → subagent-driven build:
- **Specs** (the "what" + locked decisions): `docs/superpowers/specs/` — read
  `2026-06-21-wydoujin-design.md` (the parent) plus the relevant per-feature design doc before
  any non-trivial change.
- **Plans** (the "how", task-by-task TDD): `docs/superpowers/plans/`.
Execute plans one task at a time, each a small commit, via `superpowers:subagent-driven-development`.

## What wydoujin is

A self-hosted, **single-user** reading server for a doujin/manga library (Kavita-like). Files
live on disk as `/library/<mangaka>/<doujin>.zip`. The app scans them into the DB, parses
metadata from filenames into normalized tags, groups works into series, and serves a web reader. Pages stream
straight from the zip; only resized covers are cached.

## Commands

> **Local toolchain quirk (this dev machine):** the default `php` is broken — prefix every
> `php`/`artisan`/`composer` command with `PATH="/opt/homebrew/opt/php/bin:$PATH"` (PHP 8.5).
> Node/npm are on the normal PATH. (Inside Docker, php is fine — this is local-dev only.)

```bash
php artisan test                       # full suite via Pest (in-memory SQLite); or: vendor/bin/pest
vendor/bin/pest --filter=health        # a single test / group
php -d pcov.enabled=1 vendor/bin/pest --coverage   # coverage (PCOV is loaded but OFF by default)
vendor/bin/pest tests/Browser          # Pest 4 browser suite (Playwright; explicit run, not in CI)
npm install && npx playwright install chromium     # one-time prereq for the browser suite
php artisan migrate                    # apply migrations (add --force in containers)
npm run build                          # compile Vite assets → public/build
npm run dev                            # Vite dev server with HMR

docker build -t wydoujin:dev .         # build the single runtime image
docker compose up -d                   # app (+ optional bundled MySQL) with volumes
docker compose exec app php artisan migrate --force
```

CI runs `php artisan test` on in-memory SQLite (`.github/workflows/ci.yml`); `build.yml` pushes
the image to GHCR on push to `main` and on `v*` tags.

## Where things live (implemented)

- **Routes:** `routes/web.php` (all behind the `RequirePassword` gate except `/health` + `/login`);
  `routes/console.php` (the daily scheduled scan).
- **Browse/discovery:** `BrowseController` (`/`) · `MangakaController` (`/mangaka`, `/mangaka/{slug}` —
  the latter also hosts series **manage mode**) · `SeriesController` (`/series/{id}`) ·
  `WorkController` (`/work/{id}`) · `BrowseSearchController` (`/browse` — live search + facets).
- **Reader/serving:** `ReaderController` (`/work/{id}/read`) · `PageController` (`/work/{id}/page/{n}`,
  streams bytes from the zip) · `CoverController` (`/covers/{hash}.webp`) · `ReadingProgressController`.
- **Maintenance & series:** `MaintenanceController` (`/maintenance` + `/scan`) ·
  `SeriesManagementController` (group/add/ungroup/rename — manual series ops, all DB-only).
- **Tags (F4):** `TagController` (`/tags` — global rename/merge, durable via merge-alias) ·
  `WorkTagController` (`/work/{id}/tags/{attach,detach,reset}` + `/tags/suggest` — per-work editing).
- **Backend services:** `app/Parsing/` (parser + pattern classes) · `app/Archive/` (zip inspection,
  page reader, cover gen) · `app/Scanning/LibraryScanner.php` · `app/Series/` (`SeriesDetector`,
  `TitleNormalizer`) · `app/Tagging/` (`WorkTagSync` — derive/sync tags, resolve aliases, prune
  orphans; `LegacyScalarBackfill`) · `app/Jobs/ScanLibrary.php` (the scan) ·
  `app/Jobs/GenerateCover.php` (per-work cover render, dispatched by the scanner so cover
  decoding never blocks the scan — keeps huge libraries from timing out).
- **UI:** Blade in `resources/views/`; Alpine components registered inline via `alpine:init`;
  reusable partials in `resources/views/components/` (`x-nav`, `x-cover`, `x-work-card`, `x-badge`,
  `x-button`, `x-section-heading`).

## Testing & verification

- Tests run on **Pest 4** (built on PHPUnit; `php artisan test` or `vendor/bin/pest`) on in-memory
  SQLite (matches CI). **100% line coverage** of `app/` via PCOV — the ext is loaded but OFF by
  default, so enable it: `php -d pcov.enabled=1 vendor/bin/pest --coverage`.
- **Interactive Alpine behavior** (reader nav, live search/facets, scan-status polling, series
  manage mode, the tag editor, `/tags` rename/merge) has a committed **Pest browser suite** (Pest 4
  + Playwright / real Chromium) under `tests/Browser/`. Run it explicitly: `vendor/bin/pest tests/Browser`
  — it's kept out of the default suite and CI for now (one-time prereq: `npx playwright install chromium`).
  It checks light **and** dark and asserts no console/JS errors. The ad-hoc `agent-browser` gate
  stays available for one-off manual checks; local-gate notes (serve + seed the dev SQLite;
  `LIBRARY_PATH` defaults to the non-writable `/library`; a scan needs a running
  `php artisan queue:work`) are in the project memories.

## Target architecture

- **Stack:** Laravel 13 (PHP 8.3+) · Blade + Tailwind CSS · **Alpine.js as the only JS library**
  (no SPA framework, no jQuery) · Vite · FrankenPHP · Docker · GitHub Actions · **Pest 4** for tests
  (unit/feature + a Playwright browser suite).
- **Single-image monolith:** one Docker image runs `web` (FrankenPHP, no separate nginx), the
  `scheduler` (periodic scan), and one or more `queue worker`s (scan + cover-gen jobs) under
  **s6-overlay**. Worker count = env **`QUEUE_WORKERS`** (default 1, range 1–4; the image bakes
  4 s6 worker slots — `worker`, `worker2`..`worker4` — and idle ones `sleep`). Volumes:
  `/library` (read-only), `/data` (writable: the cover cache `/data/covers` + FrankenPHP/Caddy state via `XDG_DATA_HOME`; Laravel's `storage/` stays at `/app/storage`).
- **Scanning & cover generation are separate queued jobs**, processed by the `QUEUE_WORKERS` queue
  worker(s) (default 1; the database queue's row locking keeps each job on a single worker, so
  added workers never double-process). The `ScanLibrary` scan walks the library and, for each
  newly-added work, dispatches a `GenerateCover` job another worker picks up — so the scan stays
  fast and a single bad image never fails the scan (the cover job logs and leaves `cover_path`
  null). Covers are generated with Intervention Image → `webp` under `/data/covers/`. The scan
  job carries its own long per-job timeout (`SCAN_TIMEOUT`, default 3600s) since a big first scan
  is O(library size) and outlives the queue's default 60s; `DB_QUEUE_RETRY_AFTER` is kept above
  it (defaults to `SCAN_TIMEOUT + 60`) so a running scan is never re-reserved by another worker.

### Data model (7 tables)
`mangaka` (one per top folder) · `series` (per-mangaka grouping) · `works` (one per `.zip`;
metadata lives in tags, plus a `tags_locked` flag) · `tags` (normalized `type`+`value`, with a
`merged_into_id` alias pointer) · `work_tag` (work↔tag pivot) · `reading_progress` (one per work,
kept separate so rescans never disturb progress) · `scans` (scan history/status).

## Invariants — get these wrong and later plans break

- **A work's identity is `content_hash`** — a hash of the zip's entry list (names + sizes) read
  from the central directory without decompression. **Never path.** This is what survives
  renames/moves and keeps reading progress attached.
- **Database split:** **MySQL in production; SQLite for local dev (a file) and tests
  (`:memory:`).** Migrations **must stay portable** — no MySQL-only column types and no raw SQL.
  All connection details come from env (`DB_CONNECTION`, `DB_HOST`, …); never hardcode. The one
  known divergence is full-text title search (MVP uses `LIKE`, which behaves the same on both).
- **Auth:** single-user, no users table. `APP_PASSWORD` unset → app is open; set → one password
  gate guards everything except `/health` and `/login`.
- **Filename parser** is an **ordered registry of pattern classes** (in `config/parser.php`),
  each with `matches()`/`parse()`, ending in a fallback that always matches (whole filename →
  title). Add a naming quirk = add one class + register it; don't rewrite. Mangaka always comes
  from the folder, never the filename. Real filenames are the test fixtures (TDD, written first).
- **Series detection** runs **per-mangaka only** (series never cross folders). **Never group by
  parody** (the Fate/Grand-Order trap). Manual merge/split/rename sets `series_locked` on the
  affected works, and auto-detection **never undoes a locked work**.
- **Metadata is normalized tags, not columns.** `tags(type, value)` + a `work_tag` pivot replaced
  the old scalar `circle/parody/event/author/language` + `flags` columns. Types:
  `circle·parody·event·author·flag` (scanner-derived from the parser) + `theme` (manual-only);
  `language` dropped. `WorkTagSync` writes a work's tags at scan time (one per parsed field —
  **normalize-only, no splitting**) and prunes orphans. Browse facets/filters run over the pivot
  (**6 dims**); the `/browse` URL keeps type-keyed value arrays — **no slugs** (Japanese values).
  Curation is **durable across rescans**: per-work edits set `works.tags_locked` so the scanner
  skips that work (mirror of `series_locked`); global rename/merge writes a `tags.merged_into_id`
  tombstone the scanner resolves to the canonical tag on every scan.

## Design system (vendored Apple Design System)

Lives at `resources/design-system/` (see its `SOURCE.md` for provenance; re-syncable via the
DesignSync tool from claude.ai/design project `7f55e543-1f4e-4574-afa2-2dfda16b2992`).

- **Tokens are live.** `resources/css/app.css` imports `resources/design-system/styles.css`, so
  the app inherits all CSS variables and the `[data-dark="true"]` dark theme. **Never inline a
  raw hex or size — always reference a token** (`var(--color-primary)`, `var(--radius-pill)`,
  `var(--type-body)`, …). Prefer semantic aliases (`--text-heading`, `--surface-page`,
  `--border-card`, `--focus-ring`) — they re-map automatically in dark mode.
- **Components are reference only, NOT runtime.** The 8 components (Button, Badge, Card, Input,
  NavBar, OptionChip, Segmented, Textarea) ship as React `.jsx` + `.d.ts`. Vite never imports
  them. They are the visual/interaction spec, now **re-implemented as Blade + Alpine partials**
  in `resources/views/components/` — the app is React-free.
- **House rules:** one blue accent only · weight ladder is **300/400/600/700 (no 500)** ·
  elevation is a 1px hairline ring, not a shadow (the single `--shadow-product` is reserved for
  imagery) · quiet motion (buttons press to `scale(0.95)`) · 17px body copy. Dark mode is
  `data-dark="true"` on `<html>` — no component-level dark code needed.
