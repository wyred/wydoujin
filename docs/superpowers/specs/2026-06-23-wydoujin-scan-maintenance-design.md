# wydoujin — Scan & Maintenance (F3b) Design

**Status:** approved (brainstorming, 2026-06-23). Sub-project **F3b** of the frontend.
**Parent spec:** `docs/superpowers/specs/2026-06-21-wydoujin-design.md` §7 (Scanning), §10 (Maintenance surfaces).
**Depends on:** the scanning backend (`ScanLibrary` job, `LibraryScanner`, `SeriesDetector`, `Scan` model) and F1 (nav, grid/list patterns, pager). F3a (search+filters) is done; **F3c** (manual series merge/split/rename) is the remaining sub-project.

## 1. Summary

F3b adds a single **Maintenance** surface (`GET /maintenance`) that lets the user run a library scan from the web, watch it progress live, see recent scan history, and review works that have gone missing. It sits on top of the existing async scan pipeline: a manual trigger dispatches the same `ScanLibrary` job the CLI/scheduler use. Because scans run on the **database queue** (async, processed by the worker), the page **polls** for status. The only backend change is **additive and backward-compatible** — `ScanLibrary` gains an optional scan id so a `queued` row can exist from the moment the button is clicked. Reuses F1 components and design tokens; React-free (Alpine).

## 2. Goals & Non-Goals

**Goals**
- A **Maintenance** page: trigger a scan, watch it live, see scan history, list missing works.
- **"Scan now"** dispatches `ScanLibrary` and shows live status (queued → running → completed/failed) with the result stats, without a manual reload.
- A **concurrency guard**: don't dispatch a second scan while one is queued/running.
- A **missing-works** view (informational): works flagged `is_missing` by the scanner.
- React-free (Alpine + Tailwind), tokens-only, reusing F1 components; one additive, back-compatible backend change.

**Non-Goals (later / other sub-projects)**
- Manual series merge/split/rename (F3c).
- Cancelling a running scan; retrying a failed one from the UI.
- Deleting/"forgetting" missing works (the scanner never deletes; they auto-clear when files reappear).
- WebSockets/SSE (polling only); a per-scan detail page.

## 3. Decisions (locked in brainstorming)

- **Single `/maintenance` surface** hosts the scan panel + history + missing works. New **Maintenance** nav link.
- **Live status via polling** (~2 s) while a scan is active (status `queued`|`running`); stop at a terminal state (`completed`|`failed`).
- **Record at dispatch:** `POST /scan` creates a `queued` `Scan` row, then dispatches `ScanLibrary($scan->id)` — so the row (and live status) exist from click-time, and the guard can check for any unfinished scan.
- **Additive, back-compatible backend change:** `ScanLibrary::__construct(?int $scanId = null, string $triggeredBy = 'manual')`; `handle()` updates the passed row (→ running → completed/failed) if an id is given, else creates one (→ running) exactly as today. `wydoujin:scan`, the daily scheduler, and the existing job tests (which pass no id) are unchanged.
- **Concurrency guard:** `POST /scan` does not dispatch if a scan with status `queued`|`running` already exists; it returns that scan instead.
- **Missing works are informational only** — listed, never deleted (parent spec §7); they auto-clear on the next scan when the files reappear.
- **Status colors honor the house rule** (one blue accent): `failed` uses `--color-error`; `queued`/`running`/`completed` use neutral/accent tokens — **no new green**.
- **History + status panel are Alpine-rendered from embedded JSON** (mirrors F3a's facet rows); **missing-works are server-rendered** (Blade list + F1 pager, so they work without JS and are PHPUnit-assertable).

## 4. Architecture

**Stack:** Laravel 13 Blade + Alpine.js + Tailwind v4; design tokens. No new dependencies. No schema changes.

**Routes + controller** (`MaintenanceController`)
- `GET /maintenance` → `index`, name `maintenance.index`. Renders the page: nav (`active="maintenance"`), the scan panel + history (embedded `initial` JSON for Alpine), and the server-rendered missing-works list (paginated). Passes the latest scan, the recent scans (≤ 20), the missing-works paginator, and counts.
- `POST /scan` → `scan`, name `scan.store`. If `Scan::whereIn('status', ['queued','running'])->latest()->first()` exists, return it (no dispatch). Else `Scan::create(['status' => 'queued', 'triggered_by' => 'manual'])` + `ScanLibrary::dispatch($scan->id)`; return `{ scan }` (HTTP 202). CSRF + auth via the global stack.
- `GET /maintenance/status` → `status`, name `maintenance.status`. Returns `{ scan }` — the latest scan (by `created_at`), or `null` if none — for polling.

**Scan serialization** (a small array/JSON resource, used by all three endpoints + the embedded `initial`): `{ id, status, triggered_by, stats, started_at, finished_at, created_at }` (timestamps ISO-8601; `stats` the JSON object or null).

**Backend change** (`app/Jobs/ScanLibrary.php`, additive)
- `__construct(?int $scanId = null, string $triggeredBy = 'manual')`.
- `handle()`: `$scan = $this->scanId ? Scan::find($this->scanId) : null;` if `$scan`, `$scan->update(['status' => 'running', 'started_at' => now()])`; else `$scan = Scan::create(['status' => 'running', 'triggered_by' => $this->triggeredBy, 'started_at' => now()])` (today's behavior). Then the existing scan+detect+stats flow and the completed/failed update are unchanged. (If `$scanId` was given but the row is gone, fall back to creating one.)

**Alpine `maintenance` component** (registered via `alpine:init`, like the reader/F3a)
- State (hydrated from embedded `initial`): `latest` (scan or null), `history` (array of scans), `polling` (bool), `busy` (bool, request in flight).
- `isActive(scan)` = status is `queued`|`running`. `scanning` = `latest && isActive(latest)`.
- `scan()` → POST `/scan` (CSRF header) → set `latest` from the response → `startPolling()`. Disabled while `scanning` or `busy`.
- `startPolling()` → every ~2 s, GET `/maintenance/status` → update `latest`; when `latest` reaches a terminal state, stop polling and **unshift it into `history`** (deduped by id) so the just-finished scan appears without reload.
- Renders the status panel (live) and the history rows (`x-for` over `history`) — both from this JSON state (the history-row markup lives once, in the Alpine template).

**State persistence:** none — scan state is server-side (the `scans` table); the page reads it on load and via polling.

## 5. Layout & interactions (detail)

```
┌───────────────────────────────────────────────┐
│ wydoujin   Home  Mangaka  Browse  Maintenance ☾│
├───────────────────────────────────────────────┤
│  Library                                        │
│  ┌─────────────────────────────────────────┐   │
│  │ [ Scan now ]   ● Running…  (or) ✓ Completed│  │ ← scan panel (live)
│  │ added 3 · updated 1 · missing 0 · series +1│  │   stats when done; error if failed
│  └─────────────────────────────────────────┘   │
│                                                 │
│  Recent scans                                   │
│  ┌──────────────────────────────────────────┐  │
│  │ ✓ completed · manual · 2m ago · 4s · +3/~1 │  │ ← history (Alpine x-for)
│  │ ✗ failed · scheduled · 1d ago · "boom"     │  │
│  └──────────────────────────────────────────┘  │
│                                                 │
│  Missing works (2)                              │
│  • 四畳半物語 — Z.A.P.            → /work/{id}   │ ← server-rendered + pager
│  • Another Work — Foo            → /work/{id}   │
└───────────────────────────────────────────────┘
```

- **Scan panel:** a `<x-button>` "Scan now"; beside it the live status — `queued`/`running` (with a subtle running indicator), or the terminal result with its stat summary (`completed`) / error message (`failed`). The button is disabled while a scan is active (`scanning`) or a request is in flight.
- **Polling:** begins on page load if the latest scan is active, and after a successful `scan()`. Polls `~2 s`; stops at terminal state. Quietly best-effort (a failed poll doesn't break the page; it retries next tick).
- **History:** the recent ≤ 20 scans, newest first — status (token-colored: `failed` → `--color-error`), trigger, relative start time, duration (`finished_at − started_at`), and a compact stat summary. Empty state: "No scans yet — run one."
- **Missing works:** count heading + a list (title + mangaka, linking to `/work/{id}`), paginated with the F1 prev/next pager. Empty state: "No missing works." A one-line note: they reappear automatically on the next scan when the files return.
- **Tokens only** (no raw hex/size); reuse `<x-nav>`, `<x-button>`, `<x-section-heading>`, `<x-badge>`.

## 6. Edge cases

- **No queue worker running (dev):** the dispatched job sits in the queue; the page shows `queued` and keeps polling until a worker processes it. (Prod always runs a worker; the render gate runs `php artisan queue:work`.)
- **Double-click / concurrent trigger:** the client disables the button while active, and `POST /scan` server-side returns the existing unfinished scan instead of dispatching a second — no duplicate scans.
- **Scan fails:** the job records `status='failed'` + `stats={error}` (existing behavior); the panel shows the error; polling stops.
- **`$scanId` row missing** when the job runs (deleted): the job falls back to creating a fresh running row (never crashes).
- **No scans / no missing works:** explicit empty states.
- **Poll request fails** (transient): swallowed; the next tick retries; a persistent failure just leaves the last-known state (no error spam).
- **Stats keys vary** (success vs failure): the panel/history render only the keys present.

## 7. Testing strategy

- **Feature tests** (in-memory SQLite, `RefreshDatabase`, `withoutVite()`):
  - `GET /maintenance` renders (200), nav active, embeds recent-scan data, server-renders the missing-works list (and excludes non-missing), shows empty states.
  - `POST /scan` creates a `queued` scan + dispatches `ScanLibrary` with that id (`Queue::fake`, assert `$job->scanId`); the **guard** — when a `queued`/`running` scan exists, it does NOT dispatch and returns the existing one.
  - `GET /maintenance/status` returns the latest scan (or null).
  - `ScanLibrary($scanId)` **updates** the passed row through running→completed (extend `ScanLibraryJobTest`); with no id it still **creates** one (existing tests unchanged).
  - Missing-works pagination.
- **Browser render-verify gate** (with a real `php artisan queue:work`; like F2/F3a, not PHPUnit): click "Scan now" → watch `queued → running → completed` live with stats; history gains the finished row; the button disables while active and re-enables after; the missing-works list renders; light + dark; no console errors.

## 8. Out of scope (later)

F3c (series merge/split/rename); cancel/retry a scan from the UI; delete/"forget" missing works; WebSockets/SSE; per-scan detail page; scan scheduling controls (the daily schedule stays in code).
