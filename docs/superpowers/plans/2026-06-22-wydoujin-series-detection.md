# wydoujin — Series Detection Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build per-mangaka auto series detection (spec §8) that normalizes each work's parsed `title` to a stem, groups works that share a stem (equal, or one a prefix of another at a separator boundary) into an auto `series`, assigns `works.series_id`, and re-runs safely without ever undoing a manually-locked work.

**Architecture:** A pure `TitleNormalizer` reduces a title to its series stem by stripping trailing volume/sequence tokens. A `SeriesDetector` service (pure orchestration over the DB) clusters each mangaka's non-locked works by stem, find-or-creates the auto `series`, assigns `series_id`, clears stale links, and deletes emptied auto series. The existing `ScanLibrary` job runs detection right after the scan and folds its stats into the `scans` row; a `wydoujin:series:detect` command runs it on demand.

**Tech Stack:** Laravel 13 (queued jobs, console commands), Eloquent (MySQL prod / SQLite dev+test), PHP 8.3+ `preg_*` with the `/u` flag (no `mbstring` dependency). Reuses `App\Parsing\ParsedName::deriveSortTitle()` (Plan 2) and the `ScanLibrary` job (Plan 4).

## Global Constraints

- **Framework/PHP:** Laravel 13, PHP 8.3+. Composer platform pinned `8.3.0`; local dev runs 8.5.
- **Broken local toolchain:** prefix EVERY php command with `PATH="/opt/homebrew/opt/php/bin:$PATH"` (working PHP 8.5.4). Env doesn't persist between Bash calls — repeat it. Run tests via `php artisan test`.
- **Avoid `cd` in compound bash** (it has tripped permission prompts); use absolute paths / `git -C`.
- **Commit trailer:** every commit ends with a blank line then exactly:
  `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`
- **PHP style:** single quotes unless interpolation; native typed (readonly promoted) properties over `@var`; `@param`/`@var`/`@return` only for array shapes PHP can't express; comments in BOTH English and Japanese in the same docblock, short.
- **DB portability:** MySQL (prod) + SQLite (dev/test). Use Eloquent only — no MySQL-only raw SQL. Feature tests use `RefreshDatabase` on in-memory SQLite.
- **Identity (§5):** a work is identified by `content_hash`, never by path. Detection groups on `title`, never on `parody`, `path`, or `content_hash`.
- **Per-mangaka only (§8, locked):** series never cross folders. Every cluster is scoped to one `mangaka_id`.
- **Manual override wins (§8, locked):** `works.series_locked = true` means a human decided this work's series. Auto-detection **never reads, regroups, re-links, or nulls a locked work**, and never deletes a manual (`is_auto = false`) series. Locked works are excluded from the candidate query entirely.
- **Never group by parody (§8, locked):** the Fate/Grand-Order trap. Works that share a `parody` but have distinct title stems stay standalone.
- **Workflow:** TDD, DRY, YAGNI, bite-sized commits. One failing test → minimal code → green → commit.

## Scope Decisions (locked for v1)

These resolve the spec's "how" so the engineer needn't guess. They are deliberate and final for this plan:

1. **Detection is a full re-scan, idempotent.** Every run reconsiders all non-locked works in every mangaka and converges to the same result. No incremental/dirty tracking (YAGNI for a single-user library; detection is pure in-memory DB work).
2. **The spec's "prefix of another" clause is realized two ways, both required.** (a) The normalizer strips trailing volume tokens, so `四畳半物語 二畳目` and a bare `四畳半物語` (or `四畳半物語 三畳目`) reduce to the **same** stem → grouped by equality. (b) For volume markers the normalizer's vocabulary does **not** know, two stems still merge when one is a byte-prefix of the other **and the next character is whitespace/punctuation** — so `ねこむすめ` + `ねこむすめ 黒猫編` group, but `Love` + `Lovely` and `あまあま` + `あまあまサキュバス` (no separator) do **not**. This boundary guard is what prevents false merges.
3. **A series is named after the shortest stem in its cluster** (the common prefix). `series.sort_name` is set via `ParsedName::deriveSortTitle($name)` (reuse, not reinvent).
4. **find-or-create keys on `(mangaka_id, name, is_auto = true)`.** Until the manual-merge UI exists, every series is auto. Locked-work handling is still implemented and tested now so the invariant holds once the UI arrives.
5. **Stats shape:** `detect()` returns `['series_created' => int, 'works_grouped' => int]`. `series_created` counts auto-series rows newly inserted this run (0 on a stable re-run); `works_grouped` counts works assigned to a series this run. `ScanLibrary` merges these into the `scans.stats` JSON alongside `added/updated/moved/missing/failed`.
6. **Deferred to the browse/maintenance plan (§10), explicitly out of scope here:** the manual merge/split/rename UI and endpoints (this plan only *respects* `series_locked`); populating `series.cover_work_id` (left `null`); and grouping multi-volume sets that share **no** bare-stem work and use an unknown, separator-joined suffix on *every* volume (e.g. `X 白猫編` + `X 黒猫編` with no `X`) — auto-grouping is best-effort per spec; manual merge covers the tail.

## File Structure

- `app/Series/TitleNormalizer.php` — **create**. Pure: `stem(string $title): string`. Owns the trailing-token vocabulary.
- `app/Series/SeriesDetectorContract.php` — **create**. Interface `detect(): array` (mirrors `ScannerContract`, lets the job depend on an abstraction).
- `app/Series/SeriesDetector.php` — **create**. Implements the contract; clustering + series upsert + link assignment + stale-clear + empty-series cleanup, all per-mangaka and lock-aware.
- `app/Providers/AppServiceProvider.php` — **modify**. Bind `SeriesDetectorContract` → `SeriesDetector` (Task 2).
- `app/Jobs/ScanLibrary.php` — **modify**. Run detection after the scan; merge stats (Task 4).
- `app/Console/Commands/DetectSeriesCommand.php` — **create**. `wydoujin:series:detect` (Task 5).
- `tests/Unit/Series/TitleNormalizerTest.php` — **create** (Task 1).
- `tests/Feature/Series/SeedsMangakaWorks.php` — **create**. Trait: seed a `Mangaka` + a `Work` with the required columns, no zip needed (Task 2).
- `tests/Feature/Series/SeriesDetectorTest.php` — **create** (Tasks 2 & 3).
- `tests/Feature/Series/DetectSeriesCommandTest.php` — **create** (Task 5).
- `tests/Feature/Scanning/ScanLibraryJobTest.php` — **modify**. Pass the detector to `handle()`; assert merged stats + grouping (Task 4).

**Reference — existing shapes this plan consumes (verbatim, do not re-derive):**
- `works` columns: `id, content_hash(unique,≤64), mangaka_id, series_id(nullable), relative_path, filename, title, title_raw, sort_title(nullable), event, circle, author, parody, language, flags(json), entries(json), page_count, cover_path, file_size, file_mtime, last_seen_at, is_missing, series_locked(bool,default false), timestamps`. Required-on-insert (no default, not nullable): `content_hash, mangaka_id, relative_path, filename, title, title_raw`.
- `series` columns: `id, mangaka_id, name, sort_name(nullable), is_auto(bool,default true), cover_work_id(nullable), timestamps`.
- `App\Models\Work`: `$guarded = []`; relations `mangaka()`, `series()` (BelongsTo), `readingProgress()`; casts include `series_locked => bool`, `flags => array`.
- `App\Models\Series`: `$guarded = []`; cast `is_auto => bool`; relations `mangaka()` (BelongsTo), `works()` (HasMany).
- `App\Models\Mangaka`: `$guarded = []`; relations `works()`, `series()` (HasMany).
- `App\Parsing\ParsedName::deriveSortTitle(string $title): string` — strips leading non-letter/non-digit chars; falls back to the trimmed input.
- `App\Scanning\ScannerContract::scan(): array` and `App\Jobs\ScanLibrary::handle(ScannerContract $scanner)` (Plan 4) — `handle()` gains a second injected param in Task 4.

---

## Task 1: `TitleNormalizer` — reduce a title to its series stem

**Files:**
- Create: `app/Series/TitleNormalizer.php`
- Test: `tests/Unit/Series/TitleNormalizerTest.php`

**Interfaces:**
- Consumes: nothing (pure).
- Produces: `App\Series\TitleNormalizer` — `stem(string $title): string`. Idempotent; never returns an empty string (falls back to the trimmed input).

- [ ] **Step 1: Write the failing test**

`tests/Unit/Series/TitleNormalizerTest.php`:
```php
<?php

namespace Tests\Unit\Series;

use App\Series\TitleNormalizer;
use PHPUnit\Framework\TestCase;

class TitleNormalizerTest extends TestCase
{
    private function n(): TitleNormalizer
    {
        return new TitleNormalizer();
    }

    public function test_plain_title_is_unchanged(): void
    {
        $this->assertSame('四畳半物語', $this->n()->stem('四畳半物語'));
        $this->assertSame('Love', $this->n()->stem('Love'));
        $this->assertSame('Lovely', $this->n()->stem('Lovely'));
    }

    public function test_strips_japanese_counter_suffix(): void
    {
        // "二畳目" volume marker → bare stem. / 「二畳目」を除去。
        $this->assertSame('四畳半物語', $this->n()->stem('四畳半物語 二畳目'));
        $this->assertSame('四畳半物語', $this->n()->stem('四畳半物語 三畳目'));
    }

    public function test_strips_part_and_volume_markers(): void
    {
        $this->assertSame('物語', $this->n()->stem('物語 前編'));
        $this->assertSame('物語', $this->n()->stem('物語 後編'));
        $this->assertSame('タイトル', $this->n()->stem('タイトル 上'));
        $this->assertSame('タイトル', $this->n()->stem('タイトル 下'));
        $this->assertSame('作品', $this->n()->stem('作品 第2話'));
        $this->assertSame('作品', $this->n()->stem('作品 その3'));
        $this->assertSame('作品', $this->n()->stem('作品 #4'));
    }

    public function test_strips_trailing_numbers_ascii_and_fullwidth(): void
    {
        $this->assertSame('Title', $this->n()->stem('Title 2'));
        $this->assertSame('Title', $this->n()->stem('Title 02'));
        $this->assertSame('タイトル', $this->n()->stem('タイトル２'));
    }

    public function test_never_returns_empty(): void
    {
        $this->assertSame('123', $this->n()->stem('123'));
        $this->assertSame('上', $this->n()->stem('上'));
        $this->assertSame('前編', $this->n()->stem('  前編  '));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=TitleNormalizerTest`
Expected: FAIL — `Class "App\Series\TitleNormalizer" not found`.

- [ ] **Step 3: Implement `TitleNormalizer`**

`app/Series/TitleNormalizer.php`:
```php
<?php

namespace App\Series;

/**
 * Reduces a parsed title to its series stem by stripping trailing volume/
 * sequence markers. Pure + idempotent; never returns an empty string.
 * タイトル末尾の巻数・順序マーカーを剥がしシリーズの語幹を得る。冪等。空は返さない。
 */
final class TitleNormalizer
{
    /**
     * Ordered trailing-token strippers, applied repeatedly until stable.
     * 末尾トークンの除去パターン（安定するまで反復適用）。
     *
     * @var string[]
     */
    private const SUFFIX_PATTERNS = [
        // 前編 / 後編 / 中編 / 完結編 / 最終話. / 編・話マーカー。
        '/\s*(前編|後編|中編|前篇|後篇|完結編|最終話)$/u',
        // Counter "N…目" e.g. 二畳目, 三度目, 二回目. / 「N…目」カウンタ。
        '/\s*[0-9０-９一二三四五六七八九十百千]+[^\s0-9０-９]{0,2}目$/u',
        // 第N話 / N話 / N巻 / N部 / N章 (kanji or arabic). / 第N話・N巻など。
        '/\s*第?\s*[0-9０-９一二三四五六七八九十百千]+\s*(話|巻|部|章)$/u',
        // その2 / Vol.2 / Part 2 / vol2. / 巻数表記。
        '/\s*(その|Vol|VOL|vol|Part|PART|part)\.?\s*[0-9０-９]+$/u',
        // #2 / ＃2. / シャープ番号。
        '/\s*[#＃]\s*[0-9０-９]+$/u',
        // Trailing 上 / 中 / 下 volume. / 上中下。
        '/\s*[上中下]$/u',
        // Bare trailing number, ascii or full-width. / 末尾の数字。
        '/\s*[0-9０-９]+$/u',
        // Separators left dangling. / 残った区切り。
        '/[\s\-‐―ー・:：~〜]+$/u',
    ];

    public function stem(string $title): string
    {
        $s = trim($title);
        do {
            $before = $s;
            foreach (self::SUFFIX_PATTERNS as $pattern) {
                $s = trim(preg_replace($pattern, '', $s) ?? $s);
            }
        } while ($s !== $before && $s !== '');

        return $s !== '' ? $s : trim($title);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=TitleNormalizerTest`
Expected: PASS (all assertions).

- [ ] **Step 5: Commit**

```bash
git add app/Series/TitleNormalizer.php tests/Unit/Series/TitleNormalizerTest.php
git commit -m "$(cat <<'EOF'
feat: add TitleNormalizer (strip trailing volume tokens to a series stem)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: `SeriesDetector` — cluster works and create auto series

**Files:**
- Create: `app/Series/SeriesDetectorContract.php`
- Create: `app/Series/SeriesDetector.php`
- Modify: `app/Providers/AppServiceProvider.php` (bind the contract)
- Create: `tests/Feature/Series/SeedsMangakaWorks.php` (trait)
- Test: `tests/Feature/Series/SeriesDetectorTest.php`

**Interfaces:**
- Consumes: `App\Series\TitleNormalizer::stem(string): string`; `App\Parsing\ParsedName::deriveSortTitle(string): string`; models `Mangaka`, `Series`, `Work`.
- Produces: `App\Series\SeriesDetectorContract` — `detect(): array` returning `['series_created'=>int,'works_grouped'=>int]`. `App\Series\SeriesDetector` implements it and resolves from the container via `app(SeriesDetectorContract::class)`. Private helpers `cluster(Collection $works): array<string,int[]>` and `isPrefixAtBoundary(string $prefix, string $full): bool` are introduced here and reused unchanged in Task 3.

*(This task implements the minimal grouping path: cluster non-singleton stems, find-or-create the auto series, assign `series_id`. Lock-awareness, stale-clearing, and cleanup arrive in Task 3.)*

- [ ] **Step 1: Write the seeding trait**

`tests/Feature/Series/SeedsMangakaWorks.php`:
```php
<?php

namespace Tests\Feature\Series;

use App\Models\Mangaka;
use App\Models\Work;
use Illuminate\Support\Str;

/** Seeds a Mangaka + Work directly (detection is pure DB; no zip needed). / DB直接投入。 */
trait SeedsMangakaWorks
{
    private int $hashSeq = 0;

    private function mangaka(string $name): Mangaka
    {
        return Mangaka::firstOrCreate(
            ['name' => $name],
            ['slug' => Str::slug($name) ?: 'm-'.substr(sha1($name), 0, 8)],
        );
    }

    /** @param array<string,mixed> $overrides */
    private function seedWork(string $mangaka, string $title, array $overrides = []): Work
    {
        $m = $this->mangaka($mangaka);
        $this->hashSeq++;

        return Work::create(array_merge([
            'content_hash' => str_pad((string) $this->hashSeq, 64, '0', STR_PAD_LEFT),
            'mangaka_id' => $m->id,
            'relative_path' => $mangaka.'/'.$title.'.zip',
            'filename' => $title.'.zip',
            'title' => $title,
            'title_raw' => $title,
            'sort_title' => $title,
        ], $overrides));
    }
}
```

- [ ] **Step 2: Write the failing test**

`tests/Feature/Series/SeriesDetectorTest.php`:
```php
<?php

namespace Tests\Feature\Series;

use App\Models\Series;
use App\Models\Work;
use App\Series\SeriesDetectorContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeriesDetectorTest extends TestCase
{
    use RefreshDatabase;
    use SeedsMangakaWorks;

    private function detector(): SeriesDetectorContract
    {
        return app(SeriesDetectorContract::class);
    }

    public function test_groups_multi_volume_into_one_auto_series(): void
    {
        $a = $this->seedWork('Z.A.P.', '四畳半物語');
        $b = $this->seedWork('Z.A.P.', '四畳半物語 二畳目');

        $stats = $this->detector()->detect();

        $this->assertSame(1, $stats['series_created']);
        $this->assertSame(2, $stats['works_grouped']);
        $this->assertSame(1, Series::count());
        $series = Series::firstOrFail();
        $this->assertSame('四畳半物語', $series->name);
        $this->assertTrue($series->is_auto);
        $this->assertSame($series->id, $a->refresh()->series_id);
        $this->assertSame($series->id, $b->refresh()->series_id);
    }

    public function test_all_volumes_without_a_bare_stem_still_group(): void
    {
        $this->seedWork('Z.A.P.', '四畳半物語 二畳目');
        $this->seedWork('Z.A.P.', '四畳半物語 三畳目');

        $this->detector()->detect();

        $this->assertSame(1, Series::count());
        $this->assertSame('四畳半物語', Series::firstOrFail()->name);
    }

    public function test_prefix_at_boundary_groups_unknown_suffix(): void
    {
        // 黒猫編 is not in the normalizer vocab; the space boundary still merges. / 接頭辞境界で結合。
        $this->seedWork('Circle', 'ねこむすめ');
        $this->seedWork('Circle', 'ねこむすめ 黒猫編');

        $this->detector()->detect();

        $this->assertSame(1, Series::count());
        $this->assertSame('ねこむすめ', Series::firstOrFail()->name);
    }

    public function test_prefix_without_boundary_does_not_merge(): void
    {
        $this->seedWork('Circle', 'Love');
        $this->seedWork('Circle', 'Lovely');

        $this->detector()->detect();

        $this->assertSame(0, Series::count());
        $this->assertNull(Work::where('title', 'Love')->firstOrFail()->series_id);
    }

    public function test_same_parody_distinct_titles_stay_standalone(): void
    {
        // The Fate trap: shared parody, different titles → never a series. / パロディで結合しない。
        $p = ['parody' => 'Fate/Grand Order'];
        $this->seedWork('FateCircle', 'カルデアの日常', $p);
        $this->seedWork('FateCircle', '謁見のあとで', $p);
        $this->seedWork('FateCircle', 'ぐだ子とマシュ', $p);

        $stats = $this->detector()->detect();

        $this->assertSame(0, Series::count());
        $this->assertSame(0, $stats['works_grouped']);
        $this->assertSame(0, Work::whereNotNull('series_id')->count());
    }

    public function test_single_work_makes_no_series(): void
    {
        $this->seedWork('Solo', 'ひとりぼっち');

        $this->detector()->detect();

        $this->assertSame(0, Series::count());
    }

    public function test_series_never_cross_mangaka(): void
    {
        $this->seedWork('CircleA', '同じ題');
        $this->seedWork('CircleB', '同じ題');

        $this->detector()->detect();

        $this->assertSame(0, Series::count());
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=SeriesDetectorTest`
Expected: FAIL — `App\Series\SeriesDetectorContract` not bound / not found.

- [ ] **Step 4: Create the contract**

`app/Series/SeriesDetectorContract.php`:
```php
<?php

namespace App\Series;

/** Contract for series detectors. / シリーズ検出のコントラクト。 */
interface SeriesDetectorContract
{
    /** @return array{series_created:int,works_grouped:int} */
    public function detect(): array;
}
```

- [ ] **Step 5: Implement `SeriesDetector` (minimal grouping path)**

`app/Series/SeriesDetector.php`:
```php
<?php

namespace App\Series;

use App\Models\Mangaka;
use App\Models\Series;
use App\Models\Work;
use App\Parsing\ParsedName;
use Illuminate\Support\Collection;

/**
 * Per-mangaka auto series detection (spec §8). Groups works by normalized title
 * stem; never crosses folders, never groups by parody.
 * マンガ家単位のシリーズ自動検出。フォルダを跨がず、パロディで結合しない。
 */
final class SeriesDetector implements SeriesDetectorContract
{
    public function __construct(private readonly TitleNormalizer $normalizer)
    {
    }

    public function detect(): array
    {
        $created = 0;
        $grouped = 0;

        foreach (Mangaka::all() as $mangaka) {
            $works = Work::where('mangaka_id', $mangaka->id)
                ->orderBy('id')
                ->get(['id', 'title']);

            foreach ($this->cluster($works) as $stem => $workIds) {
                if (count($workIds) < 2) {
                    continue; // singletons stay standalone / 単独作品はシリーズ化しない
                }

                $series = Series::firstOrCreate(
                    ['mangaka_id' => $mangaka->id, 'name' => $stem, 'is_auto' => true],
                    ['sort_name' => ParsedName::deriveSortTitle($stem)],
                );
                $created += $series->wasRecentlyCreated ? 1 : 0;

                Work::whereIn('id', $workIds)->update(['series_id' => $series->id]);
                $grouped += count($workIds);
            }
        }

        return ['series_created' => $created, 'works_grouped' => $grouped];
    }

    /**
     * Cluster works by shared stem. Two stems share a cluster when equal, or one
     * is a prefix of the other at a separator boundary. Cluster key = shortest stem.
     * stemでクラスタ化。等しい/区切り境界の接頭辞で同一とみなす。代表キーは最短stem。
     *
     * @param  Collection<int,Work>  $works
     * @return array<string,int[]>  stem => work ids
     */
    private function cluster(Collection $works): array
    {
        $stems = []; // [workId => stem]
        foreach ($works as $work) {
            $stems[$work->id] = $this->normalizer->stem($work->title);
        }

        // Shortest first so the common-prefix stem becomes the cluster key; byte
        // length matches the byte-based str_starts_with check below. / 最短を代表キーに。
        $distinct = array_values(array_unique(array_values($stems)));
        usort($distinct, fn (string $a, string $b): int => strlen($a) <=> strlen($b) ?: strcmp($a, $b));

        $canon = []; // [stem => canonical key]
        foreach ($distinct as $stem) {
            $key = $stem;
            foreach ($distinct as $candidate) {
                if ($candidate === $stem) {
                    break; // only shorter/earlier candidates can be a prefix / 以降は対象外
                }
                if ($this->isPrefixAtBoundary($candidate, $stem)) {
                    $key = $canon[$candidate] ?? $candidate;
                    break;
                }
            }
            $canon[$stem] = $key;
        }

        $clusters = [];
        foreach ($stems as $workId => $stem) {
            $clusters[$canon[$stem]][] = $workId;
        }

        return $clusters;
    }

    /** True if $prefix is a byte-prefix of $full and the next char is space/punct. / 区切り境界での接頭辞判定。 */
    private function isPrefixAtBoundary(string $prefix, string $full): bool
    {
        if ($prefix === '' || $prefix === $full || ! str_starts_with($full, $prefix)) {
            return false;
        }
        $remainder = substr($full, strlen($prefix));

        return (bool) preg_match('/^[\s\p{Z}\p{P}]/u', $remainder);
    }
}
```

- [ ] **Step 6: Bind the contract in `AppServiceProvider::register()`**

In `app/Providers/AppServiceProvider.php`, add imports after the namespace (alongside the existing `use` lines):
```php
use App\Series\SeriesDetector;
use App\Series\SeriesDetectorContract;
```
Append inside `register()` (keep all existing bindings intact). `SeriesDetector`'s only dependency (`TitleNormalizer`) auto-resolves, so binding the contract is enough:
```php
$this->app->bind(SeriesDetectorContract::class, fn ($app) => $app->make(SeriesDetector::class));
```

- [ ] **Step 7: Run test to verify it passes**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=SeriesDetectorTest`
Expected: PASS (7 tests).

- [ ] **Step 8: Commit**

```bash
git add app/Series/SeriesDetectorContract.php app/Series/SeriesDetector.php app/Providers/AppServiceProvider.php tests/Feature/Series/SeedsMangakaWorks.php tests/Feature/Series/SeriesDetectorTest.php
git commit -m "$(cat <<'EOF'
feat: add SeriesDetector grouping (per-mangaka, stem + prefix-boundary)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: `SeriesDetector` — locks, re-run safety, cleanup

**Files:**
- Modify: `app/Series/SeriesDetector.php` (lock filter, stale-clear, empty-series cleanup)
- Test: `tests/Feature/Series/SeriesDetectorTest.php` (add cases)

**Interfaces:**
- Consumes / Produces: unchanged signatures from Task 2. `detect()` gains three behaviors: it ignores `series_locked` works, nulls stale `series_id` on non-locked works that no longer cluster, and deletes auto series (`is_auto = true`) that end a run with zero works.

- [ ] **Step 1: Add the failing tests**

Append these methods to `tests/Feature/Series/SeriesDetectorTest.php` (inside the class):
```php
    public function test_locked_work_is_excluded_from_autodetection(): void
    {
        $a = $this->seedWork('Z.A.P.', '四畳半物語');
        $b = $this->seedWork('Z.A.P.', '四畳半物語 二畳目', ['series_locked' => true]);

        $this->detector()->detect();

        // Only one non-locked work shares the stem → no series; locked work untouched.
        // 非ロックは1作のみ→シリーズ化せず。ロック作品は不変。
        $this->assertSame(0, Series::count());
        $this->assertNull($a->refresh()->series_id);
        $this->assertNull($b->refresh()->series_id);
        $this->assertTrue($b->refresh()->series_locked);
    }

    public function test_manual_series_and_locked_links_are_never_undone(): void
    {
        $m = $this->mangaka('Z.A.P.');
        $manual = Series::create(['mangaka_id' => $m->id, 'name' => '私家版', 'is_auto' => false]);
        // Two locked works in a manual series with distinct titles (would not auto-group).
        $x = $this->seedWork('Z.A.P.', 'バラバラ題その一', ['series_id' => $manual->id, 'series_locked' => true]);
        $y = $this->seedWork('Z.A.P.', '全然ちがう題', ['series_id' => $manual->id, 'series_locked' => true]);
        $standalone = $this->seedWork('Z.A.P.', 'ぽつん');

        $this->detector()->detect();

        $this->assertNotNull(Series::find($manual->id));         // manual series preserved
        $this->assertSame($manual->id, $x->refresh()->series_id); // locked links intact
        $this->assertSame($manual->id, $y->refresh()->series_id);
        $this->assertNull($standalone->refresh()->series_id);     // non-locked standalone cleared
    }

    public function test_detect_is_idempotent(): void
    {
        $this->seedWork('Z.A.P.', '四畳半物語');
        $this->seedWork('Z.A.P.', '四畳半物語 二畳目');

        $first = $this->detector()->detect();
        $seriesId = Series::firstOrFail()->id;
        $second = $this->detector()->detect();

        $this->assertSame(1, $first['series_created']);
        $this->assertSame(0, $second['series_created']);   // already exists
        $this->assertSame(2, $second['works_grouped']);    // still grouped
        $this->assertSame(1, Series::count());             // no duplicate
        $this->assertSame($seriesId, Series::firstOrFail()->id);
    }

    public function test_rerun_clears_series_and_deletes_it_when_sibling_disappears(): void
    {
        $a = $this->seedWork('Z.A.P.', '四畳半物語');
        $b = $this->seedWork('Z.A.P.', '四畳半物語 二畳目');
        $this->detector()->detect();
        $this->assertSame(1, Series::count());

        $b->delete(); // the second volume goes missing from the library / 片方が消える
        $this->detector()->detect();

        $this->assertNull($a->refresh()->series_id);  // now standalone
        $this->assertSame(0, Series::count());         // empty auto series removed
    }
```

- [ ] **Step 2: Run the new tests to verify they fail**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=SeriesDetectorTest`
Expected: FAIL — `test_locked_work_is_excluded_from_autodetection` (locked work gets grouped), `test_manual_series_..._never_undone` (standalone not cleared / manual link may be touched), and `test_rerun_clears_...` (stale `series_id` kept, empty series not deleted). The Task 2 tests still pass.

- [ ] **Step 3: Update `SeriesDetector::detect()` with lock filter, stale-clear, and cleanup**

Replace the `detect()` method body in `app/Series/SeriesDetector.php` with:
```php
    public function detect(): array
    {
        $created = 0;
        $grouped = 0;

        foreach (Mangaka::all() as $mangaka) {
            // Auto-detection governs only non-locked works. / ロック作品は対象外。
            $works = Work::where('mangaka_id', $mangaka->id)
                ->where('series_locked', false)
                ->orderBy('id')
                ->get(['id', 'title']);

            $groupedIds = [];
            foreach ($this->cluster($works) as $stem => $workIds) {
                if (count($workIds) < 2) {
                    continue; // singletons stay standalone / 単独作品はシリーズ化しない
                }

                $series = Series::firstOrCreate(
                    ['mangaka_id' => $mangaka->id, 'name' => $stem, 'is_auto' => true],
                    ['sort_name' => ParsedName::deriveSortTitle($stem)],
                );
                $created += $series->wasRecentlyCreated ? 1 : 0;

                Work::whereIn('id', $workIds)->update(['series_id' => $series->id]);
                $grouped += count($workIds);
                $groupedIds = array_merge($groupedIds, $workIds);
            }

            // Non-locked works that no longer cluster: drop stale links. / 単独化した作品のリンク解除。
            Work::where('mangaka_id', $mangaka->id)
                ->where('series_locked', false)
                ->whereNotIn('id', $groupedIds ?: [0])
                ->whereNotNull('series_id')
                ->update(['series_id' => null]);

            // Delete auto series emptied by this run; manual series are preserved. / 空の自動シリーズ削除。
            Series::where('mangaka_id', $mangaka->id)
                ->where('is_auto', true)
                ->whereDoesntHave('works')
                ->delete();
        }

        return ['series_created' => $created, 'works_grouped' => $grouped];
    }
```
(Leave `cluster()` and `isPrefixAtBoundary()` unchanged.)

- [ ] **Step 4: Run the full Series test file to verify it passes**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=SeriesDetectorTest`
Expected: PASS (11 tests — 7 from Task 2 + 4 here).

- [ ] **Step 5: Commit**

```bash
git add app/Series/SeriesDetector.php tests/Feature/Series/SeriesDetectorTest.php
git commit -m "$(cat <<'EOF'
feat: make SeriesDetector lock-aware, idempotent, and self-cleaning

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Run detection inside the `ScanLibrary` job

**Files:**
- Modify: `app/Jobs/ScanLibrary.php`
- Modify: `tests/Feature/Scanning/ScanLibraryJobTest.php`

**Interfaces:**
- Consumes: `App\Series\SeriesDetectorContract::detect()`.
- Produces: `ScanLibrary::handle(ScannerContract $scanner, SeriesDetectorContract $detector): void` — runs the scan, then detection, then merges both stat arrays into the completed `scans.stats`. On scanner failure, detection does not run (behavior unchanged).

- [ ] **Step 1: Update the job test (add detector arg + grouping/stat assertions)**

In `tests/Feature/Scanning/ScanLibraryJobTest.php`:

(a) add imports after the existing `use App\Scanning\ScannerContract;` line:
```php
use App\Series\SeriesDetectorContract;
```

(b) replace `test_job_records_a_completed_scan_with_stats()` with:
```php
    public function test_job_runs_detection_and_folds_series_stats_into_the_scan(): void
    {
        // Two volumes of one series in one mangaka folder. Distinct entry lists →
        // distinct content_hash (else the 2nd zip looks like a move of the 1st).
        // 同一シリーズの2巻。エントリ数を変えてcontent_hashを別にする（移動誤判定の回避）。
        $this->makeDoujin('Z.A.P.', '[Z.A.P. (ズッキーニ)] 四畳半物語', ['001.jpg']);
        $this->makeDoujin('Z.A.P.', '[Z.A.P. (ズッキーニ)] 四畳半物語 二畳目', ['001.jpg', '002.jpg']);

        (new ScanLibrary('manual'))->handle(
            app(\App\Scanning\LibraryScanner::class),
            app(SeriesDetectorContract::class),
        );

        $scan = Scan::firstOrFail();
        $this->assertSame('completed', $scan->status);
        $this->assertSame(2, $scan->stats['added']);
        $this->assertSame(1, $scan->stats['series_created']);
        $this->assertSame(2, $scan->stats['works_grouped']);
        $this->assertSame(2, Work::whereNotNull('series_id')->count());
    }
```

(c) in `test_job_records_a_failed_scan_when_the_scanner_throws()`, pass a detector as the second arg (it is never reached because the scanner throws first):
```php
        (new ScanLibrary('scheduled'))->handle(app(ScannerContract::class), app(SeriesDetectorContract::class));
```

- [ ] **Step 2: Run test to verify it fails**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=ScanLibraryJobTest`
Expected: FAIL — `handle()` takes 1 argument / `series_created` key absent from stats.

- [ ] **Step 3: Update `ScanLibrary::handle()`**

In `app/Jobs/ScanLibrary.php`, add the import after `use App\Scanning\ScannerContract;`:
```php
use App\Series\SeriesDetectorContract;
```
Replace the `handle()` method with:
```php
    public function handle(ScannerContract $scanner, SeriesDetectorContract $detector): void
    {
        $scan = Scan::create([
            'status' => 'running',
            'triggered_by' => $this->triggeredBy,
            'started_at' => now(),
        ]);

        try {
            // Scan first, then group into series; merge both stat sets. / 走査→シリーズ検出→統計併合。
            $stats = array_merge($scanner->scan(), $detector->detect());
            $scan->update(['status' => 'completed', 'stats' => $stats, 'finished_at' => now()]);
        } catch (Throwable $e) {
            // Record the failure; do not re-throw (avoids retry-spamming failed scan rows).
            // 失敗を記録。再スローしない（scans行のリトライスパムを防ぐ）。
            $scan->update(['status' => 'failed', 'stats' => ['error' => $e->getMessage()], 'finished_at' => now()]);
            report($e);
        }
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=ScanLibraryJobTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/ScanLibrary.php tests/Feature/Scanning/ScanLibraryJobTest.php
git commit -m "$(cat <<'EOF'
feat: run series detection in the scan job and fold its stats into scans

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: `wydoujin:series:detect` command

**Files:**
- Create: `app/Console/Commands/DetectSeriesCommand.php`
- Test: `tests/Feature/Series/DetectSeriesCommandTest.php`

**Interfaces:**
- Consumes: `App\Series\SeriesDetectorContract::detect()` (method injection).
- Produces: artisan command `wydoujin:series:detect` — runs detection synchronously (pure DB, fast) and prints `series_created` / `works_grouped`; exit `SUCCESS`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Series/DetectSeriesCommandTest.php`:
```php
<?php

namespace Tests\Feature\Series;

use App\Models\Series;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DetectSeriesCommandTest extends TestCase
{
    use RefreshDatabase;
    use SeedsMangakaWorks;

    public function test_command_detects_series_and_reports(): void
    {
        $this->seedWork('Z.A.P.', '四畳半物語');
        $this->seedWork('Z.A.P.', '四畳半物語 二畳目');

        $this->artisan('wydoujin:series:detect')
            ->expectsOutputToContain('1 series created')
            ->assertSuccessful();

        $this->assertSame(1, Series::count());
        $this->assertSame('四畳半物語', Series::firstOrFail()->name);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=DetectSeriesCommandTest`
Expected: FAIL — command `wydoujin:series:detect` is not defined.

- [ ] **Step 3: Implement the command**

`app/Console/Commands/DetectSeriesCommand.php`:
```php
<?php

namespace App\Console\Commands;

use App\Series\SeriesDetectorContract;
use Illuminate\Console\Command;

/** Detect/refresh auto series per mangaka (synchronous; pure DB). / シリーズ自動検出を即時実行。 */
final class DetectSeriesCommand extends Command
{
    protected $signature = 'wydoujin:series:detect';
    protected $description = 'Detect and refresh auto series (per mangaka)';

    public function handle(SeriesDetectorContract $detector): int
    {
        $stats = $detector->detect();
        $this->info(sprintf(
            'Series detection complete: %d series created, %d works grouped.',
            $stats['series_created'],
            $stats['works_grouped'],
        ));

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=DetectSeriesCommandTest`
Expected: PASS.

- [ ] **Step 5: Run the full suite (no regressions)**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test`
Expected: PASS — all suites green (parser, archive, scanner, series, command).

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/DetectSeriesCommand.php tests/Feature/Series/DetectSeriesCommandTest.php
git commit -m "$(cat <<'EOF'
feat: add wydoujin:series:detect command

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Self-Review

**Spec §8 coverage:**
- "Runs per mangaka only — series never cross folders" → `detect()` loops `Mangaka::all()` and scopes every query by `mangaka_id` (Task 2/3); `test_series_never_cross_mangaka`.
- "Normalize title: strip trailing volume/sequence tokens (二畳目, 前編/後編, 上/下, trailing numbers)" → `TitleNormalizer` (Task 1) covers all listed tokens; unit-tested.
- "share a stem (equal, or one is a prefix of another) and number ≥ 2 → auto series named after the stem (`is_auto = true`)" → `cluster()` equality + `isPrefixAtBoundary()`; `count < 2` skipped; `firstOrCreate(..., is_auto = true)` named after the shortest stem (Task 2); `test_groups_multi_volume_*`, `test_all_volumes_*`, `test_prefix_at_boundary_*`, `test_single_work_*`.
- "never group by parody (Fate trap)" → detection reads only `title`; `test_same_parody_distinct_titles_stay_standalone`.
- "manual override wins … later auto-detection skips locked works, so manual decisions are never undone" → `where('series_locked', false)` filter + manual-series preservation (Task 3); `test_locked_work_is_excluded_*`, `test_manual_series_and_locked_links_are_never_undone`.
- "Expected: 四畳半物語 + 四畳半物語 二畳目 → one series 四畳半物語; Fate works standalone" → exact fixtures in `test_groups_multi_volume_into_one_auto_series` and the Fate test.
- "Auto-grouping is best-effort" → the no-bare-stem-unknown-suffix gap is documented in Scope Decision 6 and left to manual merge.

**Placeholder scan:** none — every step contains full code and an exact command with expected output.

**Type consistency:** `detect(): array{series_created:int,works_grouped:int}` is identical across the contract, `SeriesDetector`, the job merge, and the command. `cluster()`/`isPrefixAtBoundary()` are defined in Task 2 and referenced unchanged in Task 3. `stem(string): string`, `deriveSortTitle(string): string`, `ScannerContract::scan(): array`, and the `seedWork()` signature match every call site.

**Out of scope (intentional, per Scope Decisions):** manual merge/split/rename UI + endpoints (§10 browse/maintenance plan), `series.cover_work_id` population (§10), and no-bare-stem unknown-suffix grouping (best-effort tail).
