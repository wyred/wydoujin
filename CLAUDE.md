# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project status: built (F1–F3 complete, on `main`)

The app is built and merged. The full loop works end-to-end: **scan → browse/search → read →
maintain → organize.** Implemented:
- **Backend:** filename parser, archive (zip) inspection, library scanner + daily scheduled scan,
  series auto-detection, page/cover serving, reading-progress.
- **Frontend:** F1 browse (home, mangaka, series, work detail) · F2 immersive Alpine reader ·
  F3a search + faceted filters (`/browse`) · F3b scan & maintenance (`/maintenance`) · F3c manual
  series management (merge/split/rename).

Work is **document-driven**, following brainstorm → spec → plan → subagent-driven build:
- **Specs** (the "what" + locked decisions): `docs/superpowers/specs/` — read
  `2026-06-21-wydoujin-design.md` (the parent) plus the relevant per-feature design doc before
  any non-trivial change.
- **Plans** (the "how", task-by-task TDD): `docs/superpowers/plans/`.
Execute plans one task at a time, each a small commit, via `superpowers:subagent-driven-development`.

## What wydoujin is

A self-hosted, **single-user** reading server for a doujin/manga library (Kavita-like). Files
live on disk as `/library/<mangaka>/<doujin>.zip`. The app scans them into the DB, parses
metadata from filenames, groups works into series, and serves a web reader. Pages stream
straight from the zip; only resized covers are cached.

## Commands

> **Local toolchain quirk (this dev machine):** the default `php` is broken — prefix every
> `php`/`artisan`/`composer` command with `PATH="/opt/homebrew/opt/php/bin:$PATH"` (PHP 8.5).
> Node/npm are on the normal PATH. (Inside Docker, php is fine — this is local-dev only.)

```bash
php artisan test                       # full suite (in-memory SQLite)
php artisan test --filter=HealthTest   # a single test class
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
- **Backend services:** `app/Parsing/` (parser + pattern classes) · `app/Archive/` (zip inspection,
  page reader, cover gen) · `app/Scanning/LibraryScanner.php` · `app/Series/` (`SeriesDetector`,
  `TitleNormalizer`) · `app/Jobs/ScanLibrary.php`.
- **UI:** Blade in `resources/views/`; Alpine components registered inline via `alpine:init`;
  reusable partials in `resources/views/components/` (`x-nav`, `x-cover`, `x-work-card`, `x-badge`,
  `x-button`, `x-section-heading`).

## Testing & verification

- `php artisan test` runs the full suite on in-memory SQLite (matches CI).
- **Interactive Alpine behavior** (reader navigation, live search/facets, scan-status polling,
  series manage mode) is verified with a **browser render-verify gate** (the `agent-browser`
  skill), **not PHPUnit** — PHPUnit covers routes, queries, and server-rendered wiring. Verify in
  both light and dark themes. Local-gate notes (serve + seed the dev SQLite; `LIBRARY_PATH`
  defaults to the non-writable `/library`; a scan needs a running `php artisan queue:work`) are in
  the project memories.

## Target architecture

- **Stack:** Laravel 13 (PHP 8.3+) · Blade + Tailwind CSS · **Alpine.js as the only JS library**
  (no SPA framework, no jQuery) · Vite · FrankenPHP · Docker · GitHub Actions.
- **Single-image monolith:** one Docker image runs three processes under **s6-overlay** — `web`
  (FrankenPHP, no separate nginx), one `queue worker` (scan + cover-gen jobs), and the
  `scheduler` (periodic scan). Volumes: `/library` (read-only), `/data` (writable: cached
  covers + Laravel storage).
- **Scanning & cover generation are queued jobs**, processed sequentially by the one worker.
  Covers are generated with Intervention Image → `webp` under `/data/covers/`.

### Data model (5 tables)
`mangaka` (one per top folder) · `series` (per-mangaka grouping) · `works` (one per `.zip`) ·
`reading_progress` (one per work, kept separate so rescans never disturb progress) · `scans`
(scan history/status).

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
