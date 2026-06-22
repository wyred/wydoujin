# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project status: pre-scaffold, plan-driven

**The Laravel app does not exist yet.** The repo currently holds only a design spec, an
implementation plan, and a vendored design system. There is no `composer.json`,
`package.json`, `artisan`, `app/`, or `routes/` until the foundation plan is executed.

Work here is **document-driven**:
- **Spec** (the "what" and the locked decisions): `docs/superpowers/specs/2026-06-21-wydoujin-design.md`
- **Plans** (the "how", task-by-task with TDD steps): `docs/superpowers/plans/` — start with `2026-06-21-wydoujin-foundation.md` (Plan 1).

Read the spec before any non-trivial change. Plans are executed with the
`superpowers:subagent-driven-development` or `superpowers:executing-plans` skill, one task at a
time, each task a small commit. Plan 1 scaffolds the app (Laravel 13 + health route → SQLite
config → migrations → models → Tailwind/Alpine → auth gate → Intervention Image → Docker → CI).
Parser, scanning, series detection, reader, and browse UI are deferred to Plans 2–5.

## What wydoujin is

A self-hosted, **single-user** reading server for a doujin/manga library (Kavita-like). Files
live on disk as `/library/<mangaka>/<doujin>.zip`. The app scans them into the DB, parses
metadata from filenames, groups works into series, and serves a web reader. Pages stream
straight from the zip; only resized covers are cached.

## Commands (available only after Plan 1, Task 1 scaffolds the app)

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
  them. They are the visual/interaction spec to be **re-implemented as Blade + Alpine partials**
  in Plan 5, keeping the app React-free.
- **House rules:** one blue accent only · weight ladder is **300/400/600/700 (no 500)** ·
  elevation is a 1px hairline ring, not a shadow (the single `--shadow-product` is reserved for
  imagery) · quiet motion (buttons press to `scale(0.95)`) · 17px body copy. Dark mode is
  `data-dark="true"` on `<html>` — no component-level dark code needed.
