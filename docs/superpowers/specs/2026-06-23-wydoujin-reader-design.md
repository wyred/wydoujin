# wydoujin — Reader (F2) Design

**Status:** approved (brainstorming, 2026-06-23). Sub-project **F2** of the frontend.
**Parent spec:** `docs/superpowers/specs/2026-06-21-wydoujin-design.md` §9 (Reader & Page Serving).
**Depends on:** Plan 6 (page-serving + progress endpoints) and F1 (browse + work detail).

## 1. Summary

The immersive reading experience (spec §9, the JS half). A full-viewport, single-page-image reader launched from the work-detail "Read" CTA. It swaps the page image in place (no reload), navigates by keyboard + click zones with RTL/LTR direction, preloads ahead, persists reading direction + fit mode, and debounce-saves reading progress. Pure Blade + Alpine over the existing `GET /work/{work}/page/{n}` and `POST /work/{work}/progress` endpoints.

F1 (browse) is done; **F3** (search, filters, scan/maintenance, manual series merge) is a separate later sub-project, out of scope here.

## 2. Goals & Non-Goals

**Goals**
- A polished, immersive paged reader: one page at a time, fills the viewport on a dark backdrop, minimal auto-hiding chrome.
- All spec §9 behaviors: in-place `<img>`-src swap; `←`/`→` keys + left/right click zones; preload next 1–2; RTL(default)/LTR toggle, persisted; debounced `POST .../progress`.
- Resume to the saved page; a fit-height/fit-width toggle (persisted); a page slider for long works.
- React-free (Alpine + Tailwind), tokens-only styling.

**Non-Goals (later)**
- Continuous / long-strip / webtoon vertical mode.
- Double-page spreads, pinch-zoom/pan, thumbnail grid.
- "Next work in series" at the end.

## 3. Decisions (locked in brainstorming)

- **Immersive full-screen**, its own chrome (NOT the browse nav), on an **always-dark reading backdrop** (independent of the site light/dark toggle — the reader is its own context).
- **Page fit:** **fit-height by default** (whole page, `object-contain`, letterboxed), with a **fit-width toggle** (fills width, scrolls vertically). Persisted in `localStorage['wyd-reader-fit']` (`'height'|'width'`).
- **Direction:** **RTL by default** (manga), **LTR toggle**, persisted in `localStorage['wyd-reader-dir']` (`'rtl'|'ltr'`).
- **Navigation maps physical input → reading order.** Page numbers are sequential (page 1 = first to read; "next" = page+1). Inputs: left third / `←` = *go-left*; right third / `→` = *go-right*; **center third = toggle chrome**. Mapping: **RTL → go-left = next, go-right = prev; LTR → go-right = next, go-left = prev.** Bounds-clamped 1..page_count.
- **Resume:** open at the saved `current_page` when in progress (progress exists, `current_page > 0`, not completed); otherwise page 1. `?page=N` overrides (clamped).
- **CTA:** the work-detail "Read" button repoints to `/work/{id}/read`; label is **Read** (not started) / **Continue** (in progress) / **Read again** (completed).
- **Progress:** debounced (~800ms after the last page change) `POST /work/{id}/progress {current_page}` with the CSRF token; the backend marks `is_completed` when `current_page >= page_count`.
- **Preload:** the next 1–2 pages (page+1, page+2) via `new Image()`.

## 4. Architecture

**Stack:** Laravel 13 Blade + Alpine.js + Tailwind v4; design tokens. No new dependencies. Consumes the existing `work.page` (`GET /work/{work}/page/{n}`) and `work.progress` (`POST /work/{work}/progress`) routes.

**Route + controller**
- `GET /work/{work}/read` → `ReaderController@show(Work $work)`, name `work.read`. Computes `initialPage` (resume logic + `?page=N` clamp), passes `work` (id, title, `page_count`) + `initialPage` to the view.
- `routes/web.php`: register after the existing `/work/{work}` routes (distinct path; no shadowing).

**View — `resources/views/reader/show.blade.php`**
- `@extends('layouts.app')` (already nav-less; provides `<head>` + `@vite` + the theme head-script). The reader renders **no `<x-nav>`** — instead a full-viewport Alpine reader rooted in a fixed dark container (`var(--color-black)` backdrop).
- Includes the CSRF token for the Alpine progress POST (`<meta name="csrf-token">` already conventional, or pass via the Alpine data).

**Alpine reader component** — `x-data` holding:
- `id` (work id), `pages` (page_count), `page` (initialPage), `dir` (`localStorage` or 'rtl'), `fit` (`localStorage` or 'height'), `chrome` (bool, chrome visible), and an idle timer + debounce timer.
- Methods: `pageUrl(n) => `/work/${id}/page/${n}``; `goLeft()/goRight()` (map to next/prev via `dir`); `next()/prev()` (clamp); `preload()` (page+1, page+2); `saveProgress()` (debounced fetch POST with `X-CSRF-TOKEN`); `showChrome()` (reveal + reset idle timer) / auto-hide after 2.5s; `toggleChrome()`.
- A `$watch('page')` (or explicit on every change) triggers `preload()` + the debounced `saveProgress()`.
- Key handling: a `@keydown.window` for `ArrowLeft`→`goLeft()`, `ArrowRight`→`goRight()`.

**State persistence:** `dir` and `fit` write to `localStorage` on change (mirrors the F1 theme toggle pattern). `page` is server-persisted via the progress endpoint (and re-read on next open = resume).

## 5. Layout & interactions (detail)

```
┌─────────────────────────────────────────────┐
│ ←  四畳半物語                 9 / 24      ⚙  │ ← chrome: translucent dark bar,
│                                               │   auto-hides after 2.5s idle,
│              ┌───────────────┐                │   shows on move/tap, center-tap toggles
│   go-left    │   page image  │   go-right     │ ← image: fit-height (contain) or
│   (1/3 zone) │  (fit to view)│   (1/3 zone)   │   fit-width (scroll); dark backdrop
│              └───────────────┘                │
│              center 1/3 = toggle chrome       │
│  [────────●──────────────────]  (page slider) │ ← bottom: range 1..pages, x-model page
│         ⚙ popover: [RTL|LTR] [Fit H|Fit W]    │
└─────────────────────────────────────────────┘
```
- **Click/tap zones:** three vertical thirds overlaying the image — left = `goLeft()`, right = `goRight()`, center = `toggleChrome()`. (Center reserved so tapping the page doesn't accidentally navigate.)
- **Chrome:** slim top bar — `←` back to `/work/{id}` (the detail page) · work title · `current / total` · `⚙`. Backdrop is the `var(--color-black)` reading canvas; the chrome bar is a **translucent-dark scrim** + `var(--color-on-dark)` text. The design system only has a *light* translucent token (`--color-chip-translucent`), so add ONE app-specific token to `app.css` for the scrim (e.g. `--reader-scrim: rgba(0,0,0,.55)`), mirroring how `--color-error` was added — never inline a raw color in the view (per §13). Chrome auto-hides (and hides the cursor) after ~2.5s of no pointer/key activity; any activity reveals it.
- **Settings (`⚙`) popover:** two segmented toggles — direction (RTL ⇄ LTR) and fit (Fit-height ⇄ Fit-width); each persists immediately.
- **Page slider:** a bottom `range` input bound to `page` (1..pages) for jumping in long works; lives in the chrome (auto-hides with it).
- **Fit:** fit-height → `object-contain`, `max-height: 100vh`, centered (whole page). fit-width → image `width: 100%`, container scrolls vertically.
- **End of work:** advancing past the last page clamps (no-op); reaching the last page saves progress → backend sets `is_completed`.

## 6. Edge cases

- **0-page work:** the reader shows a centered "No pages." with a back link (the Read CTA can still be reached; guard cleanly).
- **Bounds:** `page` always clamped to 1..page_count; `?page=N` out of range is clamped.
- **No CSRF / failed save:** the progress POST failing is non-fatal (best-effort; the reader keeps working); errors are swallowed (debounced background save).
- **Missing page bytes:** `GET .../page/{n}` 404s (e.g. file gone) → the `<img>` simply fails to load; not a reader crash. (The browse layer already hides missing works.)

## 7. Testing strategy

- **HTTP smoke (feature, `RefreshDatabase`, `withoutVite()`):** `GET /work/{id}/read` returns 200 and wires the Alpine data — work id, `page_count`, the computed resume `initialPage`, the page-image URL pattern, and the back link to `/work/{id}`. Resume: a work with saved `current_page = 5` (not completed) opens at page 5; a completed or unread work opens at page 1; `?page=N` clamps. The work-detail CTA repoint (`/work/{id}/read`) + dynamic label (Read/Continue/Read again).
- **Interaction gate (controller, render + drive):** in the visual/interaction gate, load the reader and verify — `←`/`→` and click zones change the page image; RTL vs LTR flips the mapping; the fit toggle switches modes; the page slider jumps; chrome auto-hides/reveals; and a page change fires the debounced `POST .../progress` (confirmed via the DB row updating). The Alpine behaviors are browser-only, so this gate (not PHPUnit) is their verification — consistent with the F1 render-verify gate.

## 8. Out of scope (later sub-projects / future)

Continuous/long-strip mode; double-page spreads; zoom/pan; thumbnail grid; next-in-series at end; F3's search/filters/scan-trigger/maintenance/manual-series-merge.
