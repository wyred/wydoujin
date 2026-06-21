# wydoujin — Design Spec

**Date:** 2026-06-21
**Status:** Approved (design); pending implementation plan

## 1. Summary

A self-hosted, single-user reading server for a doujin/manga library — similar in
spirit to Kavita, but built around a specific library layout and a different
metadata model. Files live on disk as `<mangaka>/<doujin>.zip`. The app scans them
into MySQL, parses metadata from filenames, groups works into series, and serves a
web-based reader. Reading progress is stored in MySQL.

Built with Laravel 13, Blade + Tailwind, and Alpine.js as the only JS library.
Shipped as a single Docker image built by GitHub Actions. MySQL is external by
configuration (bundled container optional).

## 2. Goals & Non-Goals

### Goals
- Index a library laid out as `/library/<mangaka>/<doujin>.zip`.
- Parse rich metadata from doujin-convention filenames, with a parser that is easy
  to extend with new patterns.
- Auto-detect series within a mangaka's folder, with a reliable manual override.
- Stream pages directly from zip files; cache only resized covers.
- A clean web reader with minimal JavaScript.
- Manual + scheduled library scanning.
- Single-image Docker deployment; image built on GitHub.

### Non-Goals (for this version)
- Multiple users / accounts / per-user progress (single user, global progress).
- OPDS / external reading-app integration.
- Formats other than `.zip` (no CBR/CBZ/RAR/PDF yet).
- A normalized multi-value tag system (parsed metadata are scalar columns for now).
- Live filesystem watching.

## 3. Decisions (locked)

| Topic | Decision |
|---|---|
| Users | Single user. No user table. Global reading progress. Optional single password via `APP_PASSWORD`. |
| Series detection | Auto-detect by normalized title prefix within a mangaka **plus** manual merge/split/rename override that auto-detection never undoes. |
| Metadata | Full doujin-convention parse into structured fields, via an ordered, extendable set of pattern classes, with a graceful raw-title fallback. |
| Page serving | Stream the requested image straight from the zip on demand. No extraction to disk. Cache resized covers only. |
| Scanning | "Scan now" button + scheduled periodic scan, both as queued jobs. |
| Stack | Laravel 13 · Blade + Tailwind · Alpine.js (only JS lib) · MySQL · FrankenPHP · Docker · GitHub Actions. |
| MySQL | External by config (env-driven). Bundled `mysql` compose service is optional. |

## 4. Architecture & Deployment

### Process model (Approach A — single-image monolith)
- One Docker image runs the Laravel app under **FrankenPHP** (bundles the web
  server — no separate nginx).
- A process supervisor (**s6-overlay**) runs three processes in the container:
  1. **web** — FrankenPHP serving HTTP.
  2. **queue worker** — one worker processing scan + cover-generation jobs.
  3. **scheduler** — triggers the periodic scan.

### MySQL
- All DB connection details come from env: `DB_HOST`, `DB_PORT`, `DB_DATABASE`,
  `DB_USERNAME`, `DB_PASSWORD`.
- The shipped `docker-compose.yml` includes an **optional** `mysql` service. To use
  an external server, omit/disable that service and point the env vars at it.

### Volumes
- `/library` — the user's library, mounted **read-only**.
- `/data` — writable: cached covers (`/data/covers/`) and Laravel storage.

### Auth
- `APP_PASSWORD` unset → app is open. Set → a single password gate guards all
  routes. No user accounts.

### CI / image build
- GitHub Actions builds the image on push/tag and pushes to a registry (GHCR by
  default). Tests run in CI against a **MySQL service container** (not SQLite) so
  the test environment matches production.

## 5. Data Model (MySQL)

### `mangaka`
One row per top-level folder.
- `id`, `name` (folder name), `slug`, timestamps.

### `works`
One row per `.zip`.
- `id`
- `content_hash` (unique) — hash of the zip's entry list (names + sizes), read from
  the central directory without decompression. **This is the work's identity** and
  survives renames/moves.
- `mangaka_id` (FK), `series_id` (FK, nullable)
- `relative_path`, `filename`
- Parsed: `title`, `title_raw`, `sort_title`, `event`, `circle`, `author`,
  `parody`, `language`, `flags` (json)
- `entries` (json) — ordered list of in-zip image paths (read once at scan, used by
  the page route to avoid re-listing the archive)
- `page_count`, `cover_path`
- `file_size`, `file_mtime`, `last_seen_at`, `is_missing` (bool)
- `series_locked` (bool) — set when the user manually assigns/removes series;
  auto-detection skips locked works
- timestamps
- Indexes on `parody`, `circle`, `event`, `mangaka_id`, `series_id`, and a
  title index for search.

### `series`
- `id`, `mangaka_id` (FK), `name`, `sort_name`, `is_auto` (bool), `cover_work_id`
  (FK, nullable), timestamps.

### `reading_progress`
Separate from `works` so a rescan never disturbs progress (and future multi-user is
trivial). One row per work.
- `id`, `work_id` (FK, unique), `current_page`, `is_completed` (bool),
  `started_at`, `last_read_at`, `completed_at` (nullable).

### `scans`
Scan history / status for the UI.
- `id`, `status` (queued/running/completed/failed), `triggered_by`
  (manual/scheduled), `stats` (json: added/updated/removed/missing),
  `started_at`, `finished_at`.

## 6. Filename Parser

A pure, unit-testable component: input is a filename (minus `.zip`) plus the folder
name (mangaka); output is a normalized result object
`{event, circle, author, title, parody, language, flags[]}`.

- Implemented as an **ordered registry of pattern classes** listed in
  `config/parser.php`. Each pattern implements a common interface
  (`matches()` / `parse()`). Adding a new naming quirk = add one class + register
  it; no rewrites.
- **Standard doujin pattern:** `(EVENT) [CIRCLE (AUTHOR)] TITLE (PARODY) [FLAGS…]`
  - leading `(…)` → `event`
  - first `[…]` → split on inner parens into `circle` + `author`
  - trailing `(…)` → `parody`
  - trailing `[…]` (repeatable) → `flags` (e.g. `DL版`)
  - remainder → `title`
- **Variant patterns:** no event; `[circle]` with no inner author; no parody;
  multiple flags; Latin/English titles.
- **Fallback pattern (always matches last):** entire filename → `title`, other
  fields null. Mangaka always comes from the folder, never the filename.
- `sort_title` derived by stripping leading symbols/brackets for ordering.

### Worked examples (from real data)
- `(C89) [Z.A.P. (ズッキーニ)] 四畳半物語 (オリジナル) [DL版]`
  → event `C89`, circle `Z.A.P.`, author `ズッキーニ`, title `四畳半物語`,
  parody `オリジナル`, flags `[DL版]`.
- `相姦マニュアル` → title `相姦マニュアル`, all other fields null (fallback).
- `Two Lovers EN` → title `Two Lovers EN` (fallback).

These real filenames become the parser's test fixtures.

## 7. Scanning

Runs as queued jobs processed by the worker.

- `ScanLibrary` walks `/library/<mangaka>/*.zip`. Top-level dir = mangaka.
- **Only `.zip` is indexed.** Loose `.jpg`, `Thumbs.db`, and other files are
  ignored.
- **Fast incremental path:** if a work at the same `relative_path` has unchanged
  `file_size` + `file_mtime`, skip it.
- Otherwise read the zip's central directory and compute `content_hash`, then match
  by hash:
  - **Hash exists, different path** → file moved/renamed: update path + mangaka,
    keep progress.
  - **Hash exists, same path** → update metadata if changed.
  - **Hash not found** → new work: list image entries (filter by extension:
    jpg/jpeg/png/gif/webp/avif; skip directories and junk), **natural-sort** them
    (so 1,2,…,10 not 1,10,2; handle nested folders inside the zip), set
    `page_count` + `entries`, run the parser, and generate a cached cover
    (`/data/covers/<hash>.webp`) via Intervention Image.
- Works not seen in a scan → `is_missing = true`. **Never deleted**, so progress is
  preserved. The UI can hide or surface missing works.
- The `scans` row records added/updated/removed/missing counts.
- One worker processes sequentially. Cover generation is the heavy step and runs
  once per work.

## 8. Series Detection

- Runs **per mangaka only** — series never cross folders.
- Normalize each work's parsed `title`: strip trailing volume/sequence tokens
  (e.g. `二畳目`, `前編`/`後編`, `上`/`下`, trailing numbers) and any parody/flags.
- Works within a mangaka whose normalized titles share a stem (equal, or one is a
  prefix of another) and number ≥ 2 → an **auto series** (`is_auto = true`) named
  after the shared stem.
- **Hard rule: never group by parody** (the Fate Grand Order trap — those works
  share a parody but are not a series).
- **Manual override wins:** merge/split/rename in the UI sets `series_locked` on the
  affected works; later auto-detection skips locked works, so manual decisions are
  never undone.
- Expected outcome on sample data: `四畳半物語` + `四畳半物語 二畳目` → one series
  `四畳半物語`; the various Fate works remain standalone.

Auto-grouping is explicitly best-effort. The manual override is what makes series
reliable.

## 9. Reader & Page Serving

- `GET /work/{work}/page/{n}` — opens the zip and streams the n-th image entry's
  bytes directly, with correct content-type and a long-lived `ETag`
  (`content_hash` + page). No extraction to disk. Uses the stored `entries` list so
  it never re-lists the archive.
- `GET /covers/{hash}.webp` — served statically from `/data`.
- Cover generation uses **Intervention Image** (GD/Imagick) → resized `webp`, in
  the scan worker.

### JS (Alpine.js only)
- In-place page swap (swap `<img>` src; no full reload).
- ←/→ keyboard navigation and left/right click zones.
- Preload the next 1–2 pages.
- RTL (default, manga) / LTR reading-direction toggle, persisted.
- Debounced `POST /work/{work}/progress` to save `current_page`.
- No SPA framework. Alpine + Tailwind only. MVP reader is paged single-image
  (long-strip mode is a possible later addition).

## 10. Browse Surfaces (routes; visual design later)

- `/` — Continue Reading (in-progress works by `last_read_at`) + Recently Added.
- `/mangaka` — list of mangaka.
- `/mangaka/{slug}` — that mangaka's series + standalone works.
- `/series/{id}` — works in reading order.
- `/work/{id}` — detail (cover, parsed metadata, progress) → reader.
- Filter by `parody` / `circle` / `event` (indexed columns).
- Title search via MySQL `LIKE` over `title` + `title_raw` (FULLTEXT later if
  needed).
- Maintenance: trigger scan, scan status/history, missing-works view, manual series
  merge/split/rename.

## 11. Testing Strategy

- **Parser unit tests** — real filenames as fixtures; assert exact parsed fields.
  Written first (TDD).
- **Series-detection unit tests** — `四畳半物語` group merges; Fate works stay
  standalone; manual lock is respected on rescan.
- **Scanner feature tests** — against a small fixture library of hand-built tiny
  zips: assert rows created, incremental skip, rename-preserves-progress, missing
  flagged, cover generated.
- **Reader/serving feature tests** — correct page bytes/content-type, out-of-range
  page handling, progress endpoint updates the row.
- **HTTP smoke tests** for browse pages.
- CI runs tests against a **MySQL service container** to match production.

## 12. Open Questions / Future Extensions

- Normalized multi-value tag system (themes/multiple parodies) if scalar columns
  prove limiting.
- Additional archive formats (CBZ/CBR/RAR/PDF).
- OPDS feed for external reader apps.
- Long-strip ("webtoon") reader mode.
- Optional per-page extraction cache if streaming proves too slow for very large
  archives.

## 13. Design System

The app's visual language is the **Apple Design System**, authored on
claude.ai/design (project `7f55e543-1f4e-4574-afa2-2dfda16b2992`) and vendored into
the repo at `resources/design-system/` (see its `SOURCE.md` for provenance).

- **House style:** one blue accent, white / parchment surfaces, near-black ink,
  Inter type with tight tracking, pill-shaped controls, a single reserved shadow,
  quiet motion. Light + dark are baked into the tokens.
- **Tokens are live.** `resources/css/app.css` imports the design system's
  `styles.css`, so the whole app inherits its CSS variables and `[data-dark="true"]`
  dark mode. Components reference variables (`var(--color-primary)`,
  `var(--radius-pill)`, `var(--type-body)`, …) — never raw hex/sizes.
- **Dark mode reuse:** the reader's dark theme (§9) rides the same
  `data-dark="true"` switch — no component-level dark code needed.
- **Components are reference, not runtime.** The 8 components (Button, Badge, Card,
  Input, NavBar, OptionChip, Segmented, Textarea) ship as React `.jsx` + `.d.ts`
  under `resources/design-system/components/`. They are the visual/interaction spec
  only; each will be re-implemented as a **Blade + Alpine** partial during Plan 5
  (Browse Surfaces & UI), keeping the app React-free per §4.
- **Note:** `ds-tokens/fonts.css` loads Inter from Google Fonts at runtime;
  self-hosting Inter is a possible later step for fully offline deployment.
