# wydoujin — Browse Foundation (F1) Design

**Status:** approved (brainstorming, 2026-06-22). Sub-project **F1** of the frontend.
**Parent spec:** `docs/superpowers/specs/2026-06-21-wydoujin-design.md` (§9 Reader, §10 Browse, §13 Design System).

## 1. Summary

The first frontend slice: a **polished, read-only browse experience** so a user can see and navigate their scanned library. Builds the app shell (themed on the design-system tokens, light default + dark toggle), the reusable Blade/Alpine components the browse pages need, and five pages — Home, Mangaka index, Mangaka detail, Series, Work detail. All behind the existing password gate. HTTP-smoke-tested.

This is the foundation the rest of the frontend reuses. **F2 (Reader)** and **F3 (Search, filters & maintenance)** are separate sub-projects (each its own spec → plan), out of scope here.

## 2. Goals & Non-Goals

**Goals**
- Re-theme the app shell to use design tokens (no raw Tailwind colors), with a working light/dark toggle.
- Translate the design-system components F1 needs into Blade + Alpine partials (React-free), per the house rules.
- Five read-only browse pages with real data, covers, reading-progress indicators, and friendly empty states.
- Be genuinely usable and on-brand (the "polished" bar), not a wireframe.

**Non-Goals (later sub-projects)**
- The reader view + its Alpine behaviors (F2).
- Search, parody/circle/event filters, scan trigger, scan history/status, missing-works view, manual series merge/split/rename (F3).
- Populating `series.cover_work_id` (still derived at query time here; a later plan may persist it).

## 3. Decisions (locked in brainstorming)

- **Polished, not MVP-bare.** Use the artifact-design taste process during implementation (considered spacing rhythm, real states, hover/press), within the design-system house rules. Pixel polish is verified by rendering during implementation.
- **Theme:** light is the default; an Alpine toggle flips `data-dark="true"` on `<html>` and persists to `localStorage`. An inline head script applies the stored theme before first paint (no flash). The reader (F2) will default to dark.
- **React-free.** Components are re-implemented as **Blade components + Alpine**; the vendored `.jsx` files are visual/interaction reference only.
- **House rules (§13):** one blue accent (`--color-primary` #0066cc); weight ladder **300/400/600/700** (no 500); elevation is a **1px hairline ring**, not a shadow; quiet motion (press → `scale(0.95)`); **17px body** (`--type-body`); dark mode via `data-dark`. **Never inline a raw hex/size — always a token.**
- **Tokens, not raw Tailwind.** The current `layouts/app.blade.php` uses `bg-neutral-950 text-neutral-100`; re-theme it to `--surface-page` / `--text-body` (and their dark re-mappings).
- **Missing works hidden by default.** Browse surfaces filter `where('is_missing', false)` (spec §7: "the UI can hide or surface missing works"); surfacing them is F3's missing-works view.
- **Reader seam (F1↔F2):** the Work-detail "Read" CTA links to `/work/{work}/page/1` (the existing page endpoint — a crude but working first-page view, no dead link). **F2 replaces this CTA target with the real reader route.**

## 4. Architecture

**Stack:** Laravel 13 Blade + Tailwind v4 + Alpine.js (already wired in `app.css`/`app.js`); design tokens live via `resources/design-system/styles.css`. No new runtime dependencies.

**App shell — `resources/views/layouts/app.blade.php` (re-theme)**
- Root `<html>` carries `data-dark` from `localStorage` via a tiny inline `<head>` script (pre-paint, anti-flash).
- `<body>` uses `var(--surface-page)` / `var(--text-body)`; the existing `body { font: var(--type-body) }` from `styles.css` supplies the 17px body.
- Yields a `<x-nav>` then `@yield('content')` inside a centered max-width container.

**Theme toggle (Alpine)**
- A small Alpine component in the nav: reads/writes `localStorage['wyd-theme']` (`'light'|'dark'`), sets `document.documentElement.dataset.dark = 'true'|''`. Sun/moon control; accessible label.

**Blade components — `resources/views/components/` (translate only what F1 uses)**
- `x-nav` — brand "wydoujin" (links `/`), nav links Home + Mangaka, theme toggle. Sticky, hairline bottom border, near-black-on-light / dark-tile-on-dark. (from `NavBar` reference)
- `x-cover` — props: `:path` (a work's `cover_path`, may be null), `:title`. When present, renders `<img loading="lazy" src="{{ url($path) }}">` — `cover_path` is already `covers/<hash>.webp`, so `url($path)` yields `/covers/<hash>.webp`, which the cover route serves. Null `path` → a CSS placeholder tile showing the title. Fixed aspect ratio, hairline ring, rounded per `--radius`. (the cover treatment of `Card`)
- `x-work-card` — props: `:work` (+ optional `:progress`). Cover + title (1–2 line clamp) + circle/author subtitle + optional progress bar; links to `route('work.show', $work)`. Press `scale(0.95)`. (from `Card`)
- `x-badge` — small token-styled tag for parody/circle/event/flags. (from `Badge`)
- `x-button` — primary (blue pill) and secondary (pearl/ghost with hairline ring) variants; press `scale(0.95)`. (from `Button`)
- `x-section-heading` — section titles ("Continue Reading", "Recently Added", "Series", …) with consistent rhythm.
- **Pagination** — Tailwind paginator styled to tokens (publish + restyle, or a small custom Blade view). `app.css` already `@source`s the pagination Blade dir.

**Controllers — `app/Http/Controllers/`** (read-only; route-model binding)
- `BrowseController@home`
- `MangakaController@index`, `@show`
- `SeriesController@show`
- `WorkController@show`

**Routes — `routes/web.php`** (web group → auto-gated by `RequirePassword`)
- `GET /` → `BrowseController@home` name `home` (replaces the current `welcome` closure)
- `GET /mangaka` → `MangakaController@index` name `mangaka.index`
- `GET /mangaka/{mangaka:slug}` → `MangakaController@show` name `mangaka.show` (bind by `slug`)
- `GET /series/{series}` → `SeriesController@show` name `series.show`
- `GET /work/{work}` → `WorkController@show` name `work.show`

(The existing `/work/{work}/page/{n}`, `/work/{work}/progress`, `/covers/{hash}.webp`, `/login`, `/health` are unchanged.)

## 5. Pages

### Home `/` — `BrowseController@home`
Two sections:
- **Continue Reading** — in-progress works: query `ReadingProgress` where `current_page > 0` and `is_completed = false`, eager-load `work.mangaka`, order `last_read_at` desc, limit 12; render `x-work-card` with a progress bar (`current_page / page_count`). Skip works whose `work.is_missing` is true. Hidden entirely when empty.
- **Recently Added** — `Work::where('is_missing', false)->with('mangaka')->latest()->limit(12)` (latest = `created_at` desc); `x-work-card` grid.
- **Empty library** — when there are no works at all: a friendly empty state ("No works yet — run `wydoujin:scan` to index your library.").

```
┌──────────────────────────────────────────────┐
│ wydoujin           Home  Mangaka      ☀ / ☾   │
├──────────────────────────────────────────────┤
│ Continue Reading                               │
│ [▮ 3/24][▮ 12/40][▮ 1/18]                      │
│                                                │
│ Recently Added                                 │
│ [▢][▢][▢][▢][▢][▢]                             │
└──────────────────────────────────────────────┘
```

### Mangaka index `/mangaka` — `MangakaController@index`
- `Mangaka::withCount(['works' => fn ($q) => $q->where('is_missing', false)])->orderBy('name')->paginate(24)`.
- Card per mangaka: representative cover (first non-missing work with a `cover_path`, else placeholder), name, work count. Links to `mangaka.show`.
- Token-styled pagination. Empty state when no mangaka.

### Mangaka detail `/mangaka/{mangaka:slug}` — `MangakaController@show`
- **Series** section: `$mangaka->series` (each with a derived cover = its first work by `sort_title` that has a `cover_path`); card links to `series.show`. Hidden if none.
- **Works** section: that mangaka's standalone works (`series_id` null, `is_missing` false), ordered `sort_title`; `x-work-card` grid linking to `work.show`.

### Series `/series/{series}` — `SeriesController@show`
- Header: series name + mangaka.
- Works in reading order: `$series->works()->where('is_missing', false)->orderBy('sort_title')->get()`; `x-work-card` grid (with progress where present).

### Work detail `/work/{work}` — `WorkController@show`
- `$work->load('mangaka', 'series', 'readingProgress')`.
- Large `x-cover`; title (`title`, with `title_raw` available); mangaka (link), circle, author; `x-badge`s for parody / event / each flag; `page_count`; reading-progress line (current_page / page_count or "Not started" / "Completed").
- Primary CTA `x-button` **Read** → `/work/{work}/page/1` (F2 will repoint to the reader). Secondary link to series (if any) and mangaka.

```
┌──────────────────────────────────────────────┐
│ ┌────────┐ 四畳半物語                          │
│ │ cover  │ Z.A.P. · ズッキーニ                  │
│ │        │ [オリジナル] [C89] [DL版]            │
│ └────────┘ 24 pages · 3/24 read               │
│            ▶ Read                              │
└──────────────────────────────────────────────┘
```

## 6. Data, states & edge cases

- **Cover URL:** `url($work->cover_path)` — `cover_path` is already `covers/<hash>.webp`, so this yields `/covers/<hash>.webp`, served by the cover route from the page-serving plan. Null `cover_path` → the `x-cover` CSS placeholder.
- **Series cover (derived):** `series.cover_work_id` is null in F1; derive in the controller (first work by `sort_title` with a non-null `cover_path`), pass to `x-cover`.
- **Missing works:** filtered out of all F1 surfaces (`is_missing = false`).
- **Progress indicator:** a thin blue bar = `current_page / page_count`; "Completed" pill when `is_completed`.
- **Empty states:** home (empty library; empty Continue Reading hides its section), mangaka index, mangaka detail (no series / no works), series (no works) — friendly copy, on-brand.
- **Pagination:** mangaka index (24/page). Mangaka-detail and series lists are unpaginated in F1 (sets are small); revisit if needed (noted, not built).
- **Portability:** queries are Eloquent only (no MySQL-only SQL); ordering by `reading_progress.last_read_at` uses the `ReadingProgress` model directly (no cross-table raw SQL).

## 7. Design language

- Tokens drive everything: surfaces (`--surface-page`, dark tiles), text (`--text-heading`/`--text-body`/`--text-muted`), accent (`--color-primary`), borders (`--color-hairline`), radii/spacing/type scales — all re-map under `data-dark`.
- Covers are the visual hero (grids of art on a quiet canvas); chrome stays minimal (hairline rings, generous whitespace, one blue accent for actions/links).
- Motion is quiet: cards/buttons press to `scale(0.95)`; theme transition already eased in `styles.css`.

## 8. Testing strategy

- **HTTP smoke tests** (feature, `RefreshDatabase`, `withoutVite()`): each page returns 200 and renders seeded data (work titles, mangaka names, covers' URLs). Auth gating is already covered by `AuthGateTest` (global middleware) — not re-tested per page.
- **Home ordering/sectioning:** Continue Reading shows only in-progress (current_page>0, not completed) works, ordered by `last_read_at` desc; completed and not-started works are excluded; Recently Added ordered by `created_at` desc.
- **Empty states:** empty library renders the scan prompt; a mangaka with only standalone works (no series) renders correctly.
- **Missing works hidden:** an `is_missing` work does not appear in Recently Added / lists.
- Factories (`Mangaka`, `Series`, `Work`) + `ReadingProgress::create` seed fixtures; no zips needed (covers are referenced by URL; image bytes aren't fetched in these tests).

## 9. Open questions / future

- Persisting `series.cover_work_id` (vs deriving) — defer to a later plan.
- Pagination for large mangaka/series — add when real data warrants.
- A global search box in the nav — F3.
