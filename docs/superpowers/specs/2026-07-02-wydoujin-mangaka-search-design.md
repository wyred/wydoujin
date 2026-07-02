# Mangaka Live Search — design

Date: 2026-07-02
Status: approved, ready for planning
Parent: `2026-06-21-wydoujin-design.md` · related: `2026-06-23-wydoujin-search-filters-design.md`

## Purpose

Add a **live search bar** to the mangaka index (`/mangaka`) that filters the grid by mangaka
**name** shortly after the user stops typing — the same feel as the "Search title" input on
`/browse`. Today finding one artist among many means paging through the alphabetical grid.

## Locked decisions

- **Numbered pagination stays** (owner's choice — no switch to /browse-style infinite scroll).
  A live search swaps **both** the grid and the pagination links together; clicking a page number
  stays a normal full-page navigation.
- **Name only.** The search matches `mangaka.name` — not slugs, not work titles.
- **Same LIKE semantics as /browse:** `LIKE '%q%' ESCAPE '!'` with `!`, `%`, `_` escaped via the
  existing `!`-escape convention (portable across SQLite and MySQL — see `WorkSearch::base()`).

## Backend — extend `MangakaController::index`

No new route and no new controller. `GET /mangaka` gains two request-driven behaviors:

1. **`?q=` filter.** When present and non-empty, adds the escaped `name LIKE` where-clause to the
   existing query (count + representative-cover subquery + `orderBy(name)` + `paginate(24)` all
   unchanged). The paginator gets `appends(['q' => …])` so page links keep the search. A `?q=` in
   the URL on a full page load renders the filtered page with the input pre-filled (this is also
   the no-JS form-submit path).
2. **JSON format.** When the request wants JSON (`wantsJson()` or `format=json`, exactly like
   `BrowseSearchController`), return:

   ```json
   { "total": 123, "html": "<cards…>", "pagination": "<nav…>" }
   ```

   `html` and `pagination` are rendered from partials so the full page and the JSON response share
   one source of markup:
   - `resources/views/mangaka/_cards.blade.php` — the `<x-collection-card>` loop, extracted from
     today's `index.blade.php`.
   - `resources/views/mangaka/_pagination.blade.php` — a one-line wrapper around
     `<x-pagination :paginator=…/>` (a wrapper because `@props` components can't be rendered as
     plain views).

Live-search requests always fetch **page 1** — changing the query resets pagination.

## Frontend — small Alpine component in `mangaka/index.blade.php`

Registered inline via `alpine:init` (house pattern), mirroring the `/browse` component but much
smaller — no facets, no infinite scroll:

- **Input:** `type="search"`, `aria-label="Search mangaka"`, placeholder `Search mangaka…`, above
  the grid, styled like the /browse search pill (tokens only). Wrapped in a
  `<form action="/mangaka" method="get">` with `@submit.prevent` → JS off still works via plain GET.
- **Debounce:** `$watch('q')` → 250 ms timer → `refresh()` (same interval as /browse).
- **`refresh()`:** fetch `/mangaka?q=…&format=json`, guard with a request id (drop stale
  responses), then swap `$refs.grid.innerHTML` and `$refs.pagination.innerHTML`, update `total`.
- **URL sync:** `history.replaceState` → `/mangaka?q=…` (no `q` → bare `/mangaka`; `page` is
  always dropped when the query changes).
- **States:**
  - *No matches* (`total === 0` with a query): "No mangaka match." + a clear button.
  - *Empty library* (`total === 0`, no query): keep today's "No mangaka yet — run
    `wydoujin:scan`." message.
  - *Fetch error:* the /browse-style inline error strip with a Retry button.

The view restructures so the grid container and pagination container always exist (today the whole
grid is inside an `@if`), with the empty states toggled by Alpine.

## Testing

- **Feature (CI, in-memory SQLite):** `?q=` filters by name; LIKE metacharacters (`%`, `_`, `!`)
  match literally; pagination links carry `q`; JSON response has the `{total, html, pagination}`
  shape with matching cards; no-q behavior unchanged. Keeps the 100% line-coverage bar for `app/`.
- **Browser (`tests/Browser`, explicit suite):** type into the search box → grid updates after the
  debounce without a page load; clearing restores the full list; checked light **and** dark with
  no console/JS errors, per suite convention.

## Out of scope

- No change to `/browse`, its facets, or `WorkSearch`.
- No search on the mangaka **detail** page, series, or tags pages.
- No fuzzy matching / romaji↔kana conversion — plain substring LIKE only.
