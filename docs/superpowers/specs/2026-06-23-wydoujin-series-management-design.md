# wydoujin — Manual Series Management (F3c) Design

**Status:** approved (brainstorming, 2026-06-23). Sub-project **F3c** of the frontend — the final F3 piece.
**Parent spec:** `docs/superpowers/specs/2026-06-21-wydoujin-design.md` §8 (Series detection), §10 (Maintenance / manual series merge).
**Depends on:** the series backend (`Series` model, `SeriesDetector`, `Work.series_id`/`series_locked`, `TitleNormalizer`) and F1 (mangaka/series pages, work-card, nav, tokens). F3a (search+filters) and F3b (scan/maintenance) are done.

## 1. Summary

F3c lets the user manually fix series grouping that auto-detection got wrong: **merge** works into a series, **split** works out, **move** works between series, and **rename** a series. All operations are **per-mangaka** (series never cross folders) and set a **lock** (`series_locked`) so re-running detection never undoes a manual decision. The UI is a **"Manage" mode** on the mangaka page: a toggle reveals a flat checkable list of the mangaka's works, and a sticky action bar performs the operations.

**Library is read-only; organization is DB-only.** The `/library` zip files are provided read-only — wydoujin **never modifies them or the filesystem layout**. *All* organization (series grouping, names, the manual lock, reading progress, cached covers) lives in wydoujin's own database, keyed by `content_hash` (not path) so it survives file moves/renames. F3c is purely DB mutations (`series` rows + `works.series_id`/`series_locked`); it writes nothing to `/library`.

## 2. Goals & Non-Goals

**Goals**
- Manually **group** (merge), **ungroup** (split), and **move** works between series, plus **rename** a series — all per-mangaka.
- Make every manual decision **durable**: set `series_locked = true` (and target `is_auto = false`) so `SeriesDetector::detect()` never undoes it.
- A multi-select **Manage mode** on the mangaka page driving the operations.
- React-free (Alpine + Tailwind), tokens-only, reusing F1 patterns; DB-only (no filesystem writes).

**Non-Goals (later / out of scope)**
- Series **cover** selection (`cover_work_id`); deleting a series outright; drag-and-drop; cross-mangaka moves (series are per-mangaka by definition); bulk rename; undo/history.

## 3. Decisions (locked in brainstorming)

- **Read-only library, DB-only organization** (user-confirmed): never touch `/library`; all grouping/naming/locking is DB state.
- **Manage mode on `/mangaka/{slug}`**: a toggle (default off → the normal grouped view) switches to a **flat checkable list** of the mangaka's non-missing works (each row: checkbox + title + its current series, or "—").
- **Sticky action bar** when ≥1 work is selected: **New series** (name it) · **Add to existing series** (pick one) · **Remove from series** (ungroup) · Cancel.
- **Rename** lives on the series page (`/series/{id}`).
- **The lock contract** — every op sets `series_locked = true` on affected works; series that gain manual works or get renamed become `is_auto = false`. This is exactly what `SeriesDetector::detect()` requires to preserve them (proven by `SeriesDetectorTest`).
- **Empty `is_auto` series are deleted** immediately after a move/ungroup (mirrors the detector's self-cleaning) so the UI shows no stale auto series.
- **After a successful action the page reloads** (mutations are discrete; a reload reliably reflects the new grouping — no live DOM reconciliation).
- **New-series name default** = `TitleNormalizer::stem(first selected work's title)`; editable; required non-empty.

## 4. Architecture

**Stack:** Laravel 13 Blade + Alpine.js + Tailwind v4; design tokens. No new dependencies, no schema changes.

**Routes + controller** (`SeriesManagementController`; all `POST`, auth-gated, CSRF via the `<meta>` header)
- `POST /series/group` — `{ work_ids: int[], name: string }` → create a manual series and put the works in it.
- `POST /series/{series}/add` — `{ work_ids: int[] }` → add the works to an existing series.
- `POST /series/ungroup` — `{ work_ids: int[] }` → remove the works from their series (→ standalone).
- `POST /series/{series}/rename` — `{ name: string }` → rename the series.
- No conflict with the existing `GET /series/{series}` show route — these are all `POST`: `/series/group` and `/series/ungroup` are literal POST paths; `/series/{series}/add` and `/series/{series}/rename` are POST under the model-bound `{series}`.

**Validation (all ops)**
- `work_ids` present, non-empty, exist, and **all belong to the same mangaka** (`Work::whereIn('id',$ids)` → distinct `mangaka_id` count == 1); reject otherwise (422).
- `add`/`rename`: the `{series}` must belong to that same mangaka.
- `name`: required, non-empty (trimmed), reasonable length.

**What each op writes** (the lock contract; concrete from `SeriesDetector`)
- **group:** `Series::create(['mangaka_id'=>$mid, 'name'=>$name, 'sort_name'=>ParsedName::deriveSortTitle($name), 'is_auto'=>false])` (same `sort_name` helper the detector uses); `Work::whereIn('id',$ids)->update(['series_id'=>$new->id, 'series_locked'=>true])`; then **clean** any now-empty `is_auto` series in that mangaka.
- **add:** `$series->update(['is_auto'=>false])`; `Work::whereIn('id',$ids)->update(['series_id'=>$series->id, 'series_locked'=>true])`; then clean empty auto series.
- **ungroup:** `Work::whereIn('id',$ids)->update(['series_id'=>null, 'series_locked'=>true])`; then clean empty auto series.
- **rename:** `$series->update(['name'=>$name, 'sort_name'=>ParsedName::deriveSortTitle($name), 'is_auto'=>false])`; `$series->works()->update(['series_locked'=>true])` (lock members so the rename sticks).
- "Clean empty auto series": `Series::where('mangaka_id',$mid)->where('is_auto',true)->whereDoesntHave('works')->delete()` — identical to the detector's self-cleaning.

**Why the lock is mandatory** (`SeriesDetector::detect()`): it clusters only `where('series_locked', false)` works, clears `series_id` only on non-locked works, creates only `is_auto=true` series, and deletes only empty `is_auto=true` series. So locked works + `is_auto=false` series are untouchable by re-detection — which is what makes manual decisions permanent (`SeriesDetectorTest::test_manual_series_and_locked_links_are_never_undone`).

**Mangaka page (manage mode)** — `MangakaController@show` also passes a flat list of the mangaka's non-missing works (with each work's current series name) for the manage view; the normal grouped view (series cards + standalone) is unchanged. An Alpine `seriesManager` component holds the toggle + the selected-id set + the action calls.

**Series page (rename)** — `/series/{id}` gains a rename control (Alpine inline input → `POST /series/{id}/rename` → reload).

## 5. Layout & interactions (detail)

```
/mangaka/zap                                  [ Manage ✓ ]

 ☑ 四畳半物語              in: —
 ☑ 四畳半物語 二畳目       in: —
 ☐ ぽつん                  in: 私家版
 ☐ 別の話                  in: 別の話 (series)
 ┌ 2 selected ──────────────────────────────────────┐
 │ New series: [ 四畳半物語 ]  ( Create )            │
 │ [ Add to: ▾ existing series ]   [ Remove from series ]  [ Cancel ] │
 └────────────────────────────────────────────────────┘
```

- **Manage toggle** (top-right of the mangaka page). Off → the normal grouped view (series cards + standalone works), unchanged. On → the flat checkable work list + (when ≥1 selected) the sticky action bar.
- **Each row:** a checkbox (`accent-color: var(--color-primary)`), the work title (links to `/work/{id}` when not managing; in manage mode the row toggles selection), and a muted "in: \<series name or —\>".
- **Action bar (sticky, bottom):** shows the selected count; a **New series** name input (pre-filled with the stem of the first selected) + Create; an **Add to** dropdown of the mangaka's existing series; a **Remove from series** button; **Cancel** (clears selection / exits manage). Each action `fetch`-POSTs the selected `work_ids` (+ name / series id) with the CSRF header; on success the page reloads; on failure a token-styled error message shows.
- **Rename (series page):** a small "Rename" affordance → inline input (pre-filled with the current name) → POST → reload.
- **Tokens only**; reuse `<x-nav>`, `<x-cover>`/`<x-work-card>` (or a compact row), `<x-button>`, `<x-section-heading>`; checkboxes + the primary action use the one blue accent; errors use `--color-error`.

## 6. Edge cases

- **Cross-mangaka selection** is impossible from the per-mangaka page, but the server validates `work_ids` share one `mangaka_id` (422 otherwise).
- **Moving a work out of an `is_auto` series that becomes empty** → that auto series is deleted (cleanup step).
- **Adding to / renaming an `is_auto` series** flips it to `is_auto=false` (now manually curated; detector preserves it).
- **A work already in a series / already locked** can be re-selected and moved again (re-assign + re-lock).
- **Empty new-series name** → rejected (422); the input is required.
- **Missing works** (`is_missing`) are excluded from the manage list (consistent with the rest of the app).
- **Re-detect after any op** leaves the manual decision intact — this is asserted in tests (the contract).
- **Best-effort failures:** a failed POST shows an error and does not reload (the selection is preserved so the user can retry).

## 7. Testing strategy

- **Feature tests** (in-memory SQLite, `RefreshDatabase`, `withoutVite()`):
  - `group`: creates an `is_auto=false` series named as given; selected works get `series_id`=new + `series_locked=true`; an emptied auto series is deleted.
  - `add`: target series flips to `is_auto=false`; works get `series_id`=target + `series_locked=true`.
  - `ungroup`: works get `series_id=null` + `series_locked=true`.
  - `rename`: series `name`/`sort_name`/`is_auto=false` updated; member works locked.
  - **Lock contract:** after EACH op, running `SeriesDetector::detect()` leaves the result unchanged (manual series preserved, locked works not re-clustered).
  - **Validation:** cross-mangaka `work_ids` → 422; empty `name` → 422; `add`/`rename` with a mismatched-mangaka series → 422.
  - The mangaka page renders the manage list (works + current-series labels); the series page renders the rename control.
- **Browser render-verify gate** (like F2/F3a/F3b; not PHPUnit): toggle Manage; multi-select; New series / Add to / Remove from series; rename on the series page; the page reloads reflecting the new grouping; light + dark; no console errors.

## 8. Out of scope (later)

Series cover selection (`cover_work_id`); deleting a series outright; drag-and-drop reordering; cross-mangaka moves; bulk rename; undo/history. (F3 is complete after this.)
