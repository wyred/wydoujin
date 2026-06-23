# wydoujin — Multi-value Tags (F4) Design

**Status:** approved (brainstorming, 2026-06-23). New feature **F4** — the first beyond the F1–F3 MVP.
**Parent spec:** `docs/superpowers/specs/2026-06-21-wydoujin-design.md` §5 (the scalar `works` metadata columns), §6 (parser), §10 (browse filters / maintenance), §12 ("Normalized multi-value tag system").
**Depends on:** F1 (browse, work detail, `<x-work-card>`, `<x-badge>`, nav, tokens), F3a (search + facets — `WorkSearch`, `BrowseSearchController`), the scanner (`LibraryScanner`) + parser (`FilenameParser`), and the **`series_locked` lock contract** (mirrored here for tags).

## 1. Summary

Replace the five scalar metadata columns on `works` — `circle`, `parody`, `event`, `author`, `language` — and the `flags` JSON array with **one normalized, multi-value tag system**: a polymorphic `tags` table (`type` + `value`) joined to works through a `work_tag` pivot. A work can carry any number of tags of any type, so multiple parodies/authors/flags are first-class.

Tags are populated automatically by the scanner from the (unchanged) parser, and curated manually two ways: **per-work add/remove** on the work-detail page, and **global rename/merge** on a new `/tags` page. Both kinds of curation are **durable across rescans** via two small mechanisms that mirror existing patterns:

- **`works.tags_locked`** (exact mirror of `series_locked`) — set on any per-work edit; the scanner skips locked works, so manual tags are never re-derived away.
- **`tags.merged_into_id`** (alias/tombstone self-pointer) — rename/merge redirects an old value to a canonical tag; the scanner permanently normalizes that raw value on every scan, so a merge sticks even for the many *unlocked* works whose filenames still parse to the old value.

**Library is read-only; tags are DB-only.** Like series management, F4 never touches `/library`; every tag, link, and lock lives in wydoujin's own database, keyed off works whose identity is `content_hash`.

## 2. Goals & Non-Goals

**Goals**
- One unified `tags` (`type`,`value`) + `work_tag` model; **drop** the 6 legacy columns after a backfill.
- **Normalize only** — the parser is unchanged; each parsed field becomes one tag (flags stay multi). The schema is multi-value-capable so >1 parody/author can be populated later without a parser rewrite.
- **Full per-work curation** — add/remove tags on a work (sets `tags_locked`), plus a "revert to auto" that unlocks and re-derives.
- **Global rename/merge** on `/tags`, durable across rescans via the merge-alias.
- **Faceting preserved & widened 3 → 6 dimensions** (`circle, parody, event, author, flag, theme`); the `/browse` URL contract is unchanged.
- Tags on work cards/detail become **clickable filter-links** into `/browse` (today nothing is clickable).
- Portable migrations (SQLite + MySQL, no raw SQL); React-free Alpine + Tailwind, tokens-only.

**Non-Goals (later / out of scope)**
- Parser-side **multi-value splitting** (comma/、 within a bracket) — schema is ready; population is a later parser pattern.
- **Un-merge** / alias-management UI; bulk per-work tagging; tag covers/icons; drag-and-drop; tag import/export/OPDS.
- A **`language`** tag type (no parser source yet) and a curated **theme vocabulary** (theme is a free, manual-only type — see §3).
- Preserving **intra-type tag order** (flag order is cosmetic).

## 3. Decisions (locked in brainstorming)

- **Unified tags, all fields** (user-confirmed): `circle/parody/event/author/flag` all become tags; the scalar columns + `flags` JSON are dropped after backfill. One source of truth.
- **Types:** `Tag::TYPES = [circle, parody, event, author, flag, theme]`. `Tag::AUTO_TYPES` = the first five (the only ones the scanner ever derives). **`theme`** is manual-only — it delivers the spec's "themes" motivation with zero parser work; it simply has no auto source. `language` is **not** a type (dropped; re-add when a parser source exists).
- **Normalize only** (user-confirmed): parser untouched; one tag per parsed field; no splitting now.
- **Full per-work editing + global rename/merge** (user-confirmed).
- **Coarse lock + merge-alias** (user-confirmed):
  - `works.tags_locked` bool, default false. Any per-work add/remove sets it true; the scanner's tag sync runs only on `tags_locked = false` works (mirror of `SeriesDetector` skipping `series_locked` works). "Revert to auto" clears it and re-syncs.
  - `tags.merged_into_id` → `tags.id`, nullable. **Canonical** = `merged_into_id IS NULL`; a non-null row is a **tombstone alias** holding no pivot rows. Rename and merge both reduce to *"redirect A → B (create B if needed)"* and leave A as a tombstone.
- **No slugs** — Japanese values don't slugify (`Str::slug('四畳半物語') === ''`). Identity is the tag row; the `/browse` URL keeps human-readable, URL-encoded, type-keyed value arrays.
- **Mutations reload the page on success** (discrete ops; reliably reflects new state — same choice as series management).

## 4. Architecture

**Stack:** Laravel 13 · Blade + Alpine.js + Tailwind v4 · design tokens. No new dependencies. Schema changes per §4.1.

### 4.1 Data model

```
tags
  id · type (INDEX) · value · sort_value · merged_into_id → tags.id (nullable) · timestamps
  UNIQUE(type, value)

work_tag                                  -- pivot, no surrogate id
  work_id → works (ON DELETE CASCADE) · tag_id → tags (ON DELETE CASCADE)
  PRIMARY KEY(work_id, tag_id) · INDEX(tag_id)

works
  + tags_locked  BOOL NOT NULL DEFAULT false
  − DROP circle, parody, event, author, language, flags     (migration C, after backfill)
  keep title / title_raw / sort_title                       (the work's title, never tags)
```

- `sort_value = ParsedName::deriveSortTitle(value)` (the helper works/series already use), for ordering on `/tags`.
- `merged_into_id` is a portable self-referential nullable FK — valid on both SQLite and MySQL.

**Models**
- `Tag` — `belongsToMany(Work, 'work_tag')`; `mergedInto()` self `belongsTo`; `scopeCanonical` (`whereNull('merged_into_id')`); `TYPES`/`AUTO_TYPES` consts; `sort_value` set on create.
- `Work` — `tags()` `belongsToMany`; cast `tags_locked` bool; a `tagsByType()` / grouped accessor for views. The 6 dropped attributes are removed from all reads (see §5).

### 4.2 Parser & scanner write-path

- **Parser unchanged** — `FilenameParser` still returns a `ParsedName` with scalar `circle/parody/event/author` + `flags[]`. Parser unit tests are untouched.
- **`WorkTagSync` (new service)** — the single place that maps parsed fields → tags:
  1. If `tags_locked` → **return immediately** (manual set is authoritative).
  2. Build the auto set: one `(type,value)` per non-null `circle/parody/event/author`, one `flag` per `flags[]` element.
  3. For each, `Tag::firstOrCreate(['type'=>$t,'value'=>$v], ['sort_value'=>…])`, then **resolve through `merged_into_id`** to the canonical tag (single hop — see merge flattening).
  4. `$work->tags()->sync($canonicalIds)` — a full-set replace is safe because an unlocked work only ever holds auto tags (any manual edit locks it, and step 1 then skips it), so `sync()` never discards a manual tag.
  - It accepts an optional `ParsedName` (scan path passes the one it already parsed) or re-parses `$work->filename` with `$work->mangaka->name` (the reset path; parsed fields are no longer stored, so the **filename + parser are the source of truth**).
- `LibraryScanner::processZip` calls `WorkTagSync` instead of writing the scalar columns. **Orphan cleanup** at end of scan: delete **canonical** tags with no pivot rows **and** no inbound alias (mirrors "delete empty auto series"; tombstones and merge-targets are preserved).

### 4.3 Curation — routes & concrete write-ops

All `POST` (CSRF via the `<meta>` header), auth-gated, like `SeriesManagementController`.

Per-work (`WorkTagController`):
- `POST /work/{work}/tags/attach` — `{ type ∈ TYPES, value }` → `firstOrCreate`+resolve to canonical; `$work->tags()->syncWithoutDetaching([$id])`; `$work->update(['tags_locked'=>true])`.
- `POST /work/{work}/tags/detach` — `{ tag_id }` → `$work->tags()->detach($id)`; `$work->update(['tags_locked'=>true])`.
- `POST /work/{work}/tags/reset` — `$work->update(['tags_locked'=>false])`; run `WorkTagSync` (re-parse filename) → auto set restored.
- `GET /tags/suggest?type=&q=` — canonical values of a type matching `q` (for add-autocomplete; LIKE + `ESCAPE '!'`).

Global (`TagController`):
- `GET /tags` — management page (canonical tags per type + usage counts).
- `POST /tags/{tag}/rename` — `{ value }`. If a **canonical** `(type,value)` already exists ≠ this → delegate to **merge**. Else update `$tag` to the new `value`/`sort_value`, then insert a tombstone `Tag::create(['type'=>$tag->type,'value'=>$oldValue,'merged_into_id'=>$tag->id])`.
- `POST /tags/{tag}/merge` — `{ into_id }` (same `type`, `into` canonical, `into ≠ from`): for each `from` work not already on `into`, attach `into`; detach all `from` pivots; `from->update(['merged_into_id'=>$into->id])`; **flatten** `Tag::where('merged_into_id',$from->id)->update(['merged_into_id'=>$into->id])` (keeps alias chains one hop).

**Why the lock + alias are mandatory** (the durability contract): `WorkTagSync` re-derives only `tags_locked=false` works and `sync()`s them to the current parse — so a per-work edit (which sets the lock) is never undone, exactly as `SeriesDetector` preserves `series_locked` works. The merge-alias makes `firstOrCreate` resolve an old raw value to its canonical tag on every scan, so a rename/merge persists for unlocked works without locking them.

### 4.4 Search / facets (`WorkSearch` rewrite)

- `DIMENSIONS` → the 6 types. **URL contract unchanged:** `?q=&circle[]=&parody[]=&event[]=&author[]=&flag[]=&theme[]=`.
- **Filter:** per selected type, `whereHas('tags', fn => $q->where('type',$t)->whereIn('value',$values))` → OR-within-type, AND-across-types (semantics identical to today).
- **Dynamic facet counts:** for dimension `D`, count works under the base query + *other* selected facets, grouped by their `D`-typed canonical tag values (a `work_tag`→`tags` join filtered to the base work-set); keep selected-but-zero values visible; sort count-desc then value-asc. Tombstones (no pivots) never appear.
- `q` title search (LIKE on `title`/`title_raw`, `ESCAPE '!'`) is unchanged.
- Eager-load `tags` wherever cards render (home, mangaka, series, `/browse` results) to avoid N+1.

## 5. Layout & interactions

**Work detail** (`work/show.blade.php`)
```
四畳半物語
Z.A.P. · ズッキーニ                         ← circle/author tags, clickable
[ オリジナル ] [ C89 ] [ DL版 ]              ← parody/event/flag badges, clickable → /browse
                                            ┄ Edit tags ┄
[ circle ▾ ] [ value… (autocomplete) ] (+)   ✕ on each tag    [ Revert to auto ]
```
- Tags render as **clickable filter-links** → `/browse?{type}[]={urlencoded value}`; styling reuses `<x-badge>` (+ hover/focus ring from tokens) for parody/event/flag/theme, muted text for circle/author.
- An Alpine `workTags` editor: a type `<select>` (TYPES), a value input backed by `GET /tags/suggest`, an add button, an ✕ on each tag, and "Revert to auto". Each mutation POSTs (CSRF header) → on success reload; on failure a `--color-error` message, no reload.

**/browse** (`browse/index.blade.php`) — same checkbox-group facet UI, now 6 groups sourced from tags; empty groups (e.g. theme with no tags) are hidden. Cards show circle from the eager-loaded `tags`.

**/tags** (new `tags/index.blade.php`) — per-type sections; each canonical tag is a row: value · usage count · **Rename** (inline input) · **Merge into…** (pick another canonical value of the same type). Alpine `tagManager`, reload-on-success, token-styled. Discoverable via a nav/`/maintenance` link.

**work-card** (`components/work-card.blade.php`) — circle now from `tags` (optionally a filter-link).

**Tokens only:** reuse `<x-nav>`, `<x-badge>`, `<x-button>`, `<x-section-heading>`; one blue accent; errors `--color-error`.

## 6. Edge cases

- **Attach a tombstoned value** → resolved to its canonical tag; canonical is attached.
- **Rename onto an existing canonical value** → treated as a merge.
- **Merge guards:** `from ≠ into`, same `type`, `into` canonical (422 otherwise); chains flattened to one hop; no cycles.
- **Detach a tag the work doesn't have** → no-op (idempotent).
- **Locked work on rescan** → skipped entirely; **Revert to auto** is the only way back to auto-derivation.
- **Orphan cleanup** never deletes a tombstone or a merge-target — only canonical tags with zero works and no inbound alias.
- **Multiple same-type tags** on a work (e.g. a manual 2nd circle) are allowed; the card shows the first.
- **Empty/whitespace value** on attach/rename, or **type ∉ TYPES** → 422.
- **Japanese values** are URL-encoded in links; no slug is ever generated.
- **Backfill** sets nothing locked (all auto); a fresh/empty DB (tests) backfills to a no-op.

## 7. Migrations (portable, no raw SQL)

Three ordered migrations:
- **A** — create `tags` + `work_tag`; add `works.tags_locked`.
- **B** — **backfill** via the query builder (stable, engine-agnostic — not the evolving Eloquent models): for each work, `firstOrCreate` a tag per non-null `circle/parody/event/author` + per `flags[]` element, and insert the pivot rows.
- **C** — drop `circle, parody, event, author, language, flags` from `works`.

`down()` drops the tag tables / re-adds the columns (empty); a rollback loses tag data — acceptable and documented (forward-only in practice).

## 8. Testing strategy

- **Parser unit tests** — unchanged (parser untouched); a guard that `ParsedName` still exposes the scalar fields the scanner reads.
- **`WorkTagSync`** (feature, in-memory SQLite): derives the right `(type,value)` set incl. multi-flag; de-dupes via `firstOrCreate`; **resolves aliases** to canonical; **skips `tags_locked` works**; orphan cleanup removes only safe canonicals.
- **Scanner** — asserts tags are attached (not scalar columns); rename-keeps-progress path still green; locked work untouched on rescan.
- **`WorkSearch`/`BrowseSearch`** — rewritten to seed tags + pivot; assert preserved semantics on the 6 dims (OR-within / AND-across, dynamic counts excluding own dimension, selected-zero visible, `ESCAPE '!'`), and the unchanged JSON shape.
- **Curation** — per-work attach/detach sets `tags_locked` and survives a rescan; reset unlocks + re-derives; rename creates a tombstone that the scanner resolves; merge repoints+de-dupes pivots, flattens chains, and the merged value vanishes from facets; validation 422s.
- **Views** — work detail renders clickable tag links + the editor; `/tags` lists canonical tags + wiring; work-card shows circle.
- **Browser render-verify gate** (not PHPUnit; like F2/F3): `/browse` (6 facet groups), the work-detail tag editor (add/remove/revert), `/tags` rename + merge — reloads reflect new state; light + dark; no console errors. The **one-time backfill** is verified here against the seeded dev DB (assert tag rows + pivots), since the column-drop makes a pure unit test impractical — `WorkTagSync` covers the ongoing derivation logic.

## 9. Out of scope (later)

Parser multi-value splitting; un-merge / alias management; a curated theme vocabulary and a `language` type; bulk per-work tagging; tag covers/icons; drag-and-drop; tag export / OPDS; preserving intra-type tag order.
