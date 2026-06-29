# Full Rescan — design

Date: 2026-06-29
Status: approved, ready for planning
Parent: `2026-06-21-wydoujin-design.md` · related: `2026-06-23-wydoujin-scan-maintenance-design.md`,
`2026-06-26-wydoujin-scanner-refinement-design.md`

## Purpose

Add a **Full Rescan** action to the Maintenance page. It wipes all derived and curated metadata,
then re-runs a library scan that re-derives everything from filenames — **bypassing the incremental
fast-skip** so every zip is re-inspected, re-tagged, and re-covered.

The use case: after changing the **scanning algorithm** (parser, tagging, cover generation, series
detection), the owner wants a clean refresh rather than the normal incremental scan, which fast-skips
unchanged files and so would leave old derivations in place.

This is a **single-user, destructive, irreversible** action. The owner has chosen a **clean-slate**
wipe: nothing manual survives.

## What is preserved (never touched)

- `works` rows and their **`content_hash`** — identity must survive so reading progress stays
  attached (core invariant: identity is `content_hash`, never path).
- `reading_progress` rows.
- `mangaka` rows.
- `scans` history rows.

Files on the `/library` volume are never modified (it is read-only in prod regardless).

## What is wiped (the reset)

Run on the worker, inside the scan, **before** planning the fan-out:

1. **Cover cache:** delete every `*.webp` under `<data_path>/covers` (`config('scan.data_path').'/covers'`,
   the same dir `CoverGenerator` writes to). Missing dir is a no-op.
2. **Tag pivot:** delete all `work_tag` rows.
3. **Tags:** delete all `tags` rows — this also removes `merged_into_id` rename/merge tombstones.
4. **Series:** delete all `series` rows (auto and manual).
5. **Works (per row, no delete):** set `cover_path = null`, `tags_locked = false`,
   `series_locked = false`, `series_id = null`.

`is_missing` / `last_seen_at` are **not** reset here — the scan refreshes them: works whose files
exist are re-derived and re-stamped; works whose files are gone are swept missing by `FinalizeScan`
(rows and progress kept).

Deletes use the **query builder / Eloquent** (`DB::table('work_tag')->delete()`, `Tag::query()->delete()`,
`Series::query()->delete()`), **never `TRUNCATE`** — portable across MySQL (prod) and SQLite
(dev/tests), and respects FK ordering (pivot before tags).

## Forced rescan

A new `force` flag is threaded through the scan pipeline (default `false`, so normal scans are
byte-for-byte unchanged):

- `ScanLibrary.__construct(triggeredBy, scanId, force = false)`.
- `ScannerContract::planJobs(int $scanId, string $scanStartIso, bool $force = false)` — passes `force`
  into each emitted `ProcessZip`.
- `ProcessZip.__construct(..., bool $force = false)`:
  - **Skips the unchanged-file fast-skip** when `force` (always re-inspect + re-derive tags).
  - **Dispatches `GenerateCover` for every work with images**, including existing works matched by
    `content_hash` (the normal scan dispatches a cover only on the newly-`added` path; a forced rescan
    must also re-render covers for `updated`/`moved` works, since the cache was just cleared).

When `ScanLibrary::handle` runs with `force`, it performs **the reset** (above) right after marking
the scan `running` and before `planJobs`. If the reset throws, the existing catch marks the scan
`failed` (not left `running`).

`FinalizeScan` is unchanged: missing-sweep + orphan-prune (a no-op after a wipe), then
**series detection re-creates auto series from scratch** (all `series_locked` cleared, all series
deleted), folding counts into the final stats.

## Components

- **`app/Scanning/MetadataReset.php`** — a small service holding the wipe logic (the five steps),
  injectable and unit-testable. Takes the covers dir + does the deletes in a DB transaction (the file
  deletes happen outside the transaction; a failed file unlink is logged, not fatal). Called by
  `ScanLibrary::handle` when `force`.
- **`ScanLibrary`, `ScannerContract` / `LibraryScanner`, `ProcessZip`** — gain the `force` flag as above.
- **`app/Actions/Maintenance/TriggerScan.php`** — gains a `force` param:
  `handle(string $triggeredBy = 'manual', bool $force = false)`. Reuses its **active-scan dedupe**
  (a Full Rescan won't wipe while another scan is queued/running). Dispatches
  `ScanLibrary::dispatch($triggeredBy, $scan->id, $force)`. The shared action keeps web + (future) API
  paths from drifting.
- **`MaintenanceController::fullScan(TriggerScan $action)`** — `return response()->json(['scan' => …],
  202)` from `$action->handle('full', force: true)`. Mirrors the existing `scan()` method.
- **Route:** `POST /maintenance/full-rescan` → `MaintenanceController@fullScan`, name
  `maintenance.full-rescan`, behind the same `RequirePassword` gate as the rest of `/maintenance`.

## Frontend (`resources/views/maintenance/index.blade.php`)

- Add a secondary **"⟳ Full Rescan"** button beside "▶ Scan now", visually distinct (destructive
  intent) using design-system tokens — no raw hex/size.
- Clicking opens an **Alpine-driven styled confirm dialog** (NOT native `confirm()`): an overlay +
  panel with a **clear warning of exactly what it will do**, then **Cancel** / **Confirm**. Built with
  design-system tokens; dark mode inherits automatically. The warning copy:
  - **Heading:** *"Full Rescan — this can't be undone."*
  - **Body:** *"This permanently deletes and rebuilds everything derived from your files:"* followed
    by a short bulleted list:
    - *All tags and per-work tag edits*
    - *All tag renames and merges*
    - *All series groupings (manual and automatic)*
    - *The entire cover-image cache*
  - **Reassurance line:** *"Your files and reading progress are kept."*
  - **Closing line:** *"Everything is then re-derived from your filenames using the current scanning
    rules."*
  - The **Confirm** button is labelled *"Wipe & rebuild"* and styled as the destructive action.
- **Confirm** posts to `/maintenance/full-rescan` and then starts the **existing** status polling.
  The `scan()` Alpine method is generalised to take an endpoint (`scan('/scan')` vs
  `scan('/maintenance/full-rescan')`) so both buttons share one code path and one `latest`/`history`
  panel.
- Both buttons are disabled (`scanning || busy`) while a scan is queued/running.
- History rows already render `triggered_by`; a Full Rescan shows as **`full`**. Most works match by
  `content_hash`, so its stats read mostly as `updated`.

## Edge cases & invariants

- **Identity preserved:** works are never deleted, so `content_hash` and `reading_progress` survive
  the wipe.
- **Dedupe:** Full Rescan is a no-op (returns the active scan) if a scan is already queued/running —
  never wipes mid-scan.
- **Clean slate is intentional:** clearing `tags_locked`/`series_locked` means subsequent normal scans
  treat every work as fully auto again until re-curated. Tombstones are gone, so a previously-renamed
  tag re-derives under its filename-derived value.
- **Covers transient-missing:** between wipe and regeneration covers 404 / fall back to the placeholder
  the `x-cover` component already handles.
- **Portability:** no `TRUNCATE`, no raw SQL, no MySQL-only types.

## Testing

Project standard is **100% line coverage of `app/`** (PCOV) and a Pest 4 suite on in-memory SQLite.

- **`MetadataReset`:** seed locked works (manual tags), a `merged_into_id` tombstone, auto + manual
  series, and on-disk cover files → run reset → assert: all tags/pivot/tombstones/series gone, every
  cover file deleted, works kept with `reading_progress` intact and `cover_path`/`tags_locked`/
  `series_locked`/`series_id` cleared. Missing-covers-dir is a no-op.
- **`ProcessZip` force:** an unchanged file (same size+mtime) is **re-derived, not skipped**, when
  `force` and **dispatches `GenerateCover`**; without `force` it still fast-skips (regression guard).
- **Feature:** `POST /maintenance/full-rescan` creates a `full`, deduped scan and returns 202; is
  behind the password gate; dedupes against an active scan.
- **Browser (explicit suite):** the Full Rescan button opens the confirm dialog, Cancel closes it,
  Confirm triggers a scan and the panel reflects it — checked in light **and** dark with no
  console/JS errors.

## Out of scope

- No new scheduler entry (Full Rescan is manual-only; the daily scan stays incremental).
- No per-mangaka or partial reset (whole library only).
- No undo/backup of wiped curation (clean-slate is the chosen behaviour).
- No API endpoint in this iteration (the shared `TriggerScan` action leaves the door open).
