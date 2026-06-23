# wydoujin — Search + Filters (F3a) Design

**Status:** approved (brainstorming, 2026-06-23). Sub-project **F3a** of the frontend.
**Parent spec:** `docs/superpowers/specs/2026-06-21-wydoujin-design.md` §10 (Browse Surfaces).
**Depends on:** F1 (browse foundation — grid, `<x-work-card>`, `<x-cover>`, `<x-nav>`, tokens) and the scanner/parser (which populate `title`, `title_raw`, `circle`, `parody`, `event`, `is_missing`).

## 1. Summary

F3 (the library's discovery + management surfaces) is split into **three independent sub-projects**, each with its own spec → plan → build:

- **F3a — Search + Filters** (this doc): find works by title; filter by circle / parody / event.
- **F3b — Scan & Maintenance** (later): trigger a scan, scan status/history, missing-works view.
- **F3c — Series Management** (later): manual merge / split / rename of series.

F3a adds one **Browse** surface (`GET /browse`): a live title search plus a faceted filter rail (circle / parody / event) that together drive a grid of work cards. It is server-rendered and deep-linkable; Alpine keeps it live without a full reload. It reuses F1's grid, `<x-work-card>`, `<x-cover>`, and design tokens. **No new dependencies, no schema changes** — the filter columns are already indexed.

## 2. Goals & Non-Goals

**Goals**
- One **/browse** surface combining free-text search + faceted filtering over the library.
- **Live** results: typing in search or toggling a facet updates the grid **and the facet counts** without a full reload, debounced.
- **Faceted filtering** on `circle` / `parody` / `event`: multi-select within a facet (OR), combined across facets (AND), with **dynamic counts** that reflect the current query.
- **Deep-linkable**: the URL reflects the active query (`q`, `circle[]`, `parody[]`, `event[]`), so a view is shareable/bookmarkable and the back button works.
- React-free (Alpine + Tailwind), tokens-only styling, reusing F1 components.

**Non-Goals (later / other sub-projects)**
- Scan/maintenance UI (F3b); series merge/split/rename (F3c).
- Searching fields other than `title` + `title_raw` (no circle/author free-text search).
- Sort options (newest / A–Z), saved searches, numbered pagination.
- MySQL `FULLTEXT` search — MVP uses portable `LIKE` (parent spec §4/§10).

## 3. Decisions (locked in brainstorming)

- **Three sub-projects**, build order F3a → F3b → F3c. This doc is **F3a only**.
- **Single Browse surface** `/browse` hosts both search and facets (not separate pages).
- **Live/instant search**, debounced (~250 ms), over `title` + `title_raw` with case-insensitive `LIKE %q%` (portable across SQLite/MySQL).
- **Left-sidebar facet rail** (search box on top, then Circle / Parody / Event groups); results grid fills the rest. Collapses to a "Filters" toggle/drawer on narrow screens.
- **Multi-select within a facet (OR), AND across facets.**
- **Dynamic facet counts:** each facet value's count reflects the current search **and the other facets' selections**, but **not its own facet's selections** — standard multi-select faceting, so the remaining values in a facet stay selectable with meaningful counts. Recomputed on every change.
- **HTML-over-the-wire for cards, JSON for counts:** the results grid is server-rendered (`<x-work-card>` stays the single source); the live endpoint returns JSON `{ total, hasMore, facets, html }`, where `html` is the rendered cards partial and `facets` carries the recomputed counts. Facet rows are rendered by Alpine from the JSON (trivial markup); the search input stays mounted so focus/caret survive updates.
- **"Load more" pagination** (append the next page); no numbered pages for MVP.
- **Operates over not-missing works** (`is_missing = false`), consistent with F1.

## 4. Architecture

**Stack:** Laravel 13 Blade + Alpine.js + Tailwind v4; design tokens. No new dependencies. **No schema changes** (`circle`/`parody`/`event` already indexed; `title` indexed; `title_raw` present).

**Route + controller**
- `GET /browse` → `BrowseSearchController@index`, name `browse.index`.
  - **HTML request** → full page: layout + nav (`active="browse"`) + facet rail + first page of results + embedded initial state for Alpine.
  - **JSON request** (`Accept: application/json`, or `?format=json`) → `{ total, page, hasMore, facets: { circle: [{value, count}], parody: […], event: […] }, html }`, where `html` is the rendered **cards-only** partial for the requested page.
- Query params (all optional, repeatable): `q` (string), `circle[]`, `parody[]`, `event[]` (arrays of exact values), `page` (int ≥ 1).
- New nav link **Browse** in `<x-nav>` (after Mangaka).

**Query model** (a `Work` query helper / `app/Browse/` service; Eloquent only, portable, no raw SQL)
- **Base constraints:** `is_missing = false` + (when `q` non-empty) `where(fn ($w) => $w->where('title','like',"%$q%")->orWhere('title_raw','like',"%$q%"))`.
- **Results:** base + for each facet with selections `whereIn(col, values)` + `orderBy('sort_title')` + paginate (`page`, ~60/page).
- **Facet counts (dynamic):** for each facet dimension *D*, count works matching base + every **other** facet's `whereIn` (excluding *D*'s own selection), grouped by *D*, non-null, ordered by count desc then value. Three `GROUP BY` queries on the indexed columns. (No selections anywhere → counts are full per-value totals.)

**View + partials**
- `resources/views/browse/index.blade.php` — `@extends('layouts.app')`; the Alpine root (`x-data="browse(initial)"`), the facet rail, the results container, result count, empty state.
- `resources/views/browse/_cards.blade.php` — grid cells only: `@foreach ($works as $work) <x-work-card :work="$work" /> @endforeach`. Rendered on first paint **and** returned (as `html`) by the JSON endpoint via `view(...)->render()`. **Single source for the card.**
- Facet rows are Alpine-rendered (`x-for` over the JSON facet arrays) — trivial markup (checkbox + label + count), not worth a server partial.

**Alpine component** — `Alpine.data('browse', initial => ({ … }))`, registered via `alpine:init` (like the reader):
- State: `q`, `selected { circle:[], parody:[], event:[] }`, `facets { circle:[…], parody:[…], event:[…] }`, `total`, `page`, `hasMore`, `loading`, `expanded` (per-facet show-more), `reqId` (stale-response guard).
- Hydrated from `initial` (server-embedded JSON built from the request params).
- Methods: `refresh()` (reset `page=1`, fetch JSON, **replace** grid html, update facets/total, sync URL); `loadMore()` (`page++`, fetch, **append**); `toggle(dim, value)`; `clear()`; `syncUrl()` (`history.replaceState`); a debounced watcher on `q`.
- Fetches `GET /browse?…&format=json`; sets the results container's innerHTML from `html`; binds facet rows + counts reactively.

**State persistence / URL**
- The query lives entirely in the URL (`replaceState`), so the view is shareable and back/forward friendly. Initial page load parses params → server renders that exact state → embeds it for Alpine.

## 5. Layout & interactions (detail)

```
┌───────────────────────────────────────────────┐
│ wydoujin   Home  Mangaka  Browse            ☾  │
├───────────────┬─────────────────────────────────┤
│ 🔎 [search…]  │  328 works                       │
│               │  ┌──┐┌──┐┌──┐┌──┐┌──┐┌──┐         │
│ CIRCLE        │  │  ││  ││  ││  ││  ││  │         │
│ ☑ Z.A.P.  12  │  └──┘└──┘└──┘└──┘└──┘└──┘         │
│ ☐ Foo      8  │  ┌──┐┌──┐┌──┐┌──┐┌──┐┌──┐         │
│ + show more   │  │  ││  ││  ││  ││  ││  │         │
│               │  └──┘└──┘└──┘└──┘└──┘└──┘         │
│ PARODY        │           [ Load more ]          │
│ ☑ FGO     40  │                                  │
│ EVENT         │                                  │
│ ☐ C99     15  │                                  │
└───────────────┴─────────────────────────────────┘
```

- **Facet rail (left):** search box on top; then Circle / Parody / Event groups. Each group shows the top **15** values by count (checkbox + label + count), a **"show more"** to reveal the rest (Alpine `expanded`; full list shipped in initial state — no extra request), and a small **"filter within"** text box that trims the visible rows client-side for big facets. **Checked values always stay visible** (even at count 0) so they can be unchecked.
- **Live updates:** typing (debounced ~250 ms) or toggling a checkbox → `refresh()` → grid replaced, **counts updated**, URL synced. The search input never unmounts (caret/focus preserved).
- **Results (right):** the F1 grid (`grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: var(--grid-gutter)`) of `<x-work-card>`; a **result count** above it; **"Load more"** appends the next page; an **empty state** ("No works match" + "Clear filters") when total = 0.
- **Responsive:** below a breakpoint the rail becomes a "Filters" toggle that opens a drawer (Alpine); grid goes full-width.
- **Tokens only** (no raw hex/size); reuse `<x-work-card>`, `<x-cover>`; facet checkboxes + count chips styled with tokens (the `<x-badge>` blue tint for counts/active state — one blue accent).

## 6. Edge cases

- **Empty `q` + no facets:** the full library (not-missing); counts = per-value totals.
- **No matches:** empty state + "Clear filters"; unselected zero-count facet rows hide, selected ones remain so they can be removed.
- **Selected value drops to 0** under other facets: keep it visible (disabled/struck) so it can be unchecked.
- **Huge facet (hundreds of values):** top-15 + show-more + filter-within keeps the rail bounded; the full list ships once in initial state.
- **Rapid typing:** debounce + **drop stale responses** (ignore a response whose `reqId` is not the latest).
- **JS disabled / first paint:** the server applies all URL params and renders the correct results grid on first load, so deep links resolve before (and without) JS; the facet rail's rows hydrate from embedded JSON via Alpine, so the interactive filter controls need JS.
- **Deep link:** `/browse?q=foo&circle[]=Z.A.P.&parody[]=FGO` renders exactly that filtered state server-side, then hydrates Alpine.

## 7. Testing strategy

- **Feature tests** (in-memory SQLite, `RefreshDatabase`, `withoutVite()`):
  - `/browse` renders (200); shows not-missing works; excludes missing.
  - `q` filters by `title` **and** `title_raw` (case-insensitive); empty `q` = all.
  - Each facet filters; **OR within** a facet; **AND across** facets; combined with `q`.
  - **Dynamic counts:** selecting a parody changes circle/event counts; a facet's own selection does **not** reduce its own values' counts; no-selection counts = totals.
  - JSON endpoint shape `{ total, hasMore, facets, html }`; `html` contains the work cards; `page`/Load-more returns the next slice.
  - Empty state when nothing matches.
- **Browser render-verify gate** (like F2; not PHPUnit): live debounce; grid replace on search/facet; counts update; "show more" + "filter within"; Load-more append; URL sync + deep-link hydration; responsive drawer; light + dark.

## 8. Out of scope (later)

Scan/maintenance (F3b); series merge/split/rename (F3c); search over circle/author/parody text; sort options; saved searches; numbered pagination; `FULLTEXT`.
