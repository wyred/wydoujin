# wydoujin — Scanner Refinement Design Spec

**Date:** 2026-06-26
**Status:** Approved (design); pending implementation plan
**Parent:** `2026-06-21-wydoujin-design.md` · touches the parser (`app/Parsing/`),
the scanner (`app/Scanning/LibraryScanner.php`), the scan job (`app/Jobs/ProcessZip.php`),
and tag derivation (`app/Tagging/WorkTagSync.php`).

## 1. Summary

The built scanner was written against an assumed layout of `/<mangaka>/<doujin>.zip`
(exactly one level deep, mangaka = top folder, metadata = filename brackets). A real
3,516-file library reveals four mismatches that lose data or mis-file works. This spec
refines the scanner/parser to handle the real library while keeping every locked
invariant from the parent design.

The unifying idea: **every derived field is a pure function of a work's stored
`relative_path` plus its zip filename.** That single rule keeps tags durable across
rescans (the existing `WorkTagSync` re-derives from stored fields, not from scan-time
context), and it is the backbone of the whole design.

## 2. Findings (from the real library)

Measured against `scratchpad-library-listing.txt` (3,516 files, 466 top folders):

1. **~147 files are never scanned.** `LibraryScanner` discovers zips with
   `glob('<folder>/*.zip')` — one level only. Nested files exist: `_series/<X>/*.zip`
   (138) and `Kakao/Specials/*.zip` (9). They are invisible.
2. **~1,570 files (≈45%) lose parody + flags.** `StandardDoujinPattern::matches()`
   only fires when the filename *starts* with `(` or `[`. Title-first names like
   `羽川ちゃんは語りたい (化物語) [DL版].zip` fall to the catch-all, so the whole name
   becomes the title and the trailing `(化物語)` (parody) and `[DL版]` (flag) are dropped.
3. **`_`-prefixed folders become fake mangaka.** `_series`, `_small`, `_雑誌` are each
   treated as an artist. For `_small`/`_series` the real circle/author lives in the
   filename; `_series` is a manual *franchise* grouping (the subfolder is a parody,
   not an artist, and crosses circles — which would violate the "series are per-mangaka,
   never group by parody" invariant if mapped onto the app's `series`).
4. **Folder names encode the artist but aren't parsed.** 344 folders are
   `Romaji - Japanese` (`Aiueoka - 愛上陸`, two scripts of one name); 14 are
   `Circle (Author)` (`華容道 (松果)`). The mangaka is stored as the raw folder string and
   the structure inside is unused — so the `author` facet is empty for the ~45% of
   title-first files whose filenames carry no author.

## 3. Decisions (locked)

| Topic | Decision |
|---|---|
| Discovery depth | Recursive find under each top folder (was one level). |
| Subfolder inside a real mangaka (`Kakao/Specials/`) | **Flatten** — work belongs to the top-folder mangaka; subfolder name is ignored. Normal title-based series auto-detection still runs. |
| `_series/<X>/` | Bucket. Mangaka derived from the **filename**; `<X>` becomes a **parody** tag (de-duped against any filename parody). |
| `_small/` | Bucket. Mangaka derived from the **filename**. |
| `_雑誌/` and any other `_*` folder | **Unchanged** — stays a literal mangaka named after the folder. Only `_series` and `_small` are recognised buckets (explicit allowlist). |
| No derivable artist (rare; ~1–2 files) | Fall back to a single `Unknown` sentinel mangaka so the file is still scanned, never dropped. |
| Title-first filenames | New pattern peels trailing `(parody)` + `[flags]` even with no leading bracket. |
| `circle - title` filenames | New pattern, **applied only inside `_series`/`_small` buckets**, to recover the artist from bracket-less names. |
| Folder→author | `Circle (Author)` → circle + author tags. `Romaji - Japanese` / plain → an **author** tag = the full folder name. Populates the author facet library-wide. |
| Multi-value derived tags | A work may now hold more than one parody or author tag (folder-derived + filename-derived), de-duped on identical `(type,value)`. |
| Identity | Unchanged. A work's identity stays `content_hash`; none of this touches it. Moving a file between any of these layouts keeps reading progress. |

## 4. Architecture

### 4.1 The path-metadata layer (`PathMetadata`)

A new value-producing resolver — pure, no DB — turns a single library-relative path
into everything the scanner needs:

```
PathMetadata::resolve(relativePath) -> {
    mangakaName: string,         // folder for normal; filename-derived for buckets; 'Unknown' fallback
    parsed:      ParsedName,     // filename parse, enriched with folder/subfolder tags
}
```

Steps:
1. Split `relativePath` into segments. The first segment is the top folder; the last
   is the basename.
2. Classify the top folder: **normal**, **bucket** (`_series` or `_small`), or
   **other-`_`** (treated as normal-but-literal — folder is the mangaka).
3. Run the `FilenameParser` on the basename, telling it whether this is a bucket
   path so `CircleTitlePattern` is consulted only there (see §4.3).
4. Enrich:
   - **Normal / other-`_`:** mangakaName = top folder. Add folder-derived
     circle/author (see §4.4) as `extraTags`.
   - **Bucket:** mangakaName = filename-derived `circle`/`author` (prefer author, then
     circle); if none, `Unknown`. For `_series`, add the middle subfolder as an
     `extraTags` parody.
5. Return the mangaka name and the enriched `ParsedName`.

Because it depends only on `relativePath` (+ the basename within it), both the scan
path and the rescan/re-derive path produce identical results.

### 4.2 Discovery + no-race resolution (`LibraryScanner`)

- `mangakaFolders()` / per-folder `glob('*.zip')` is replaced by a **recursive**
  enumeration of every `*.zip` under the library root (e.g. a `RecursiveDirectoryIterator`
  filtered to `.zip`), yielding library-relative paths.
- For each path, `planJobs` calls `PathMetadata::resolve` to learn the mangaka name,
  then resolves/creates the `Mangaka` **sequentially in `planJobs`** — preserving the
  current guarantee that concurrent `ProcessZip` tasks never race to create the same
  mangaka. (Buckets resolve their filename-derived mangaka here too.)
- `ProcessZip` is dispatched with the already-resolved `mangakaId` + `relativePath`.
  Its constructor signature is unchanged.

### 4.3 Filename patterns (`config/parser.php`, ordered registry)

Registry order becomes:

```
StandardDoujinPattern        // leading (event)/[circle] — unchanged
CircleTitlePattern           // 'circle - title' — ONLY honoured for bucket paths
TrailingMetadataPattern      // title-first: peel trailing (parody) + [flags]
FallbackPattern              // whole filename → title (unchanged)
```

- `TrailingMetadataPattern` shares the bracket-peeling helpers with
  `StandardDoujinPattern` (factor the trailing-peel logic into a shared trait/helper
  rather than duplicating it).
- `CircleTitlePattern::matches()` is gated so it only applies inside `_series`/`_small`.
  Cleanest implementation: `PathMetadata` decides whether to *consult* it (passes a
  "bucket" flag), so a normal title containing ` - ` is never mis-split. (Concrete
  gating mechanism — a context flag vs. a bucket-only sub-registry — is an
  implementation choice for the plan; the behaviour is fixed: split only in buckets.)

### 4.4 Folder-name parsing (`MangakaFolder`)

A tiny parser over the top-folder string:
- Trailing `(...)` present → `circle = before`, `author = inside`.
- Contains ` - ` (and no trailing `()`), or a plain single token → `author = whole folder string`.
- These become `extraTags` on the `ParsedName` for normal folders. (Not applied to
  bucket paths, where the artist comes from the filename.)

### 4.5 Tag flow (`ParsedName` + `WorkTagSync`)

- `ParsedName` gains `array $extraTags` (a list of `[type, value]`), defaulting to
  empty, carried through `make()`.
- `WorkTagSync::derive()` appends `extraTags` to the pairs it already emits from the
  scalar fields + flags. `sync()` continues to `array_unique` the resolved tag ids, so
  a folder author identical to the filename author collapses to one tag, and an
  `_series` parody identical to the filename parody collapses to one.
- `WorkTagSync::sync()`'s null-`$parsed` branch (rescans) must reconstruct the same
  enrichment: it re-derives from the work's stored `relative_path` via `PathMetadata`
  instead of calling `FilenameParser` on the bare basename. This is the change that
  keeps folder/subfolder tags durable across `RescanWork`.

## 5. Data model

No schema changes. All new metadata rides on the existing normalized tag model:
`tags(type, value)` + `work_tag`. The only new "field" is the in-memory
`ParsedName::extraTags`; nothing is persisted that isn't already (`relative_path`,
`mangaka_id`, the tag pivot). `tags_locked` / merge-alias semantics are untouched —
locked works are still skipped, aliases still resolve to canonical tags.

## 6. Testing

- **TDD**, real filenames from the library as fixtures (the parser's existing
  convention — fixtures written first). Cover, at minimum:
  - title-first with parody + flags; title-first bare; `circle - title` in a bucket;
    `_series/<X>/` parody derivation + de-dup against filename parody; `_small` artist
    from filename; nested file under a real mangaka (flatten); `Circle (Author)` and
    `Romaji - Japanese` folder derivation; the `Unknown` fallback; a normal title
    containing ` - ` that must **not** be split.
  - Durability: scan → rescan re-derives an identical tag set for a `_series` work and
    a folder-author work (the regression this design most needs to guard).
- Target stays **100% line coverage of `app/`** via PCOV.
- Existing feature/browser suites must stay green.

## 7. Scope & non-goals

**In scope:** the four findings above and only those.

**Known minor imperfections (accepted, easy future pattern classes):**
- A trailing `(EN)` / `(オリジナル)` is captured into the parody slot (language/
  "original" markers aren't special-cased).
- Trailing `[誉]`-style tokens are treated as flags.

**Out of scope:** any cross-artist "collection" browse concept for `_series` (rejected
in favour of parody tags); CBR/RAR/PDF formats; live filesystem watching; schema
changes.

## 8. Risks

- **Rescan drift** is the highest risk: if any derived tag is *not* reconstructible
  from `relative_path` + filename, a rescan would silently strip it. Mitigated by
  §4.5 and an explicit durability test (§6).
- **Mangaka-creation races** if filename-derived mangaka were resolved inside parallel
  `ProcessZip` jobs. Mitigated by keeping resolution sequential in `planJobs` (§4.2).
- **Over-eager ` - ` splitting** mangling normal titles. Mitigated by gating
  `CircleTitlePattern` to buckets only (§4.3) plus a guard test (§6).
