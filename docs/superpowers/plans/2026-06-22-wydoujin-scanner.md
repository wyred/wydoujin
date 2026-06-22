# wydoujin — Library Scanner Orchestration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the queued library scanner (spec §7) that walks `/library/<mangaka>/*.zip`, syncs each work into the DB by `content_hash`, generates covers, flags missing works, and records scan stats.

**Architecture:** A `LibraryScanner` service (pure orchestration over the DB + the already-built `App\Archive` units + `App\Parsing\FilenameParser`) does the walk/match/missing logic and returns stats. A thin `ScanLibrary` queued job owns the `scans` row lifecycle (running → completed/failed). A `wydoujin:scan` command dispatches the job; a scheduled entry dispatches it periodically. `config/scan.php` + container bindings supply paths/settings.

**Tech Stack:** Laravel 13 (queued jobs, console commands, scheduler), Eloquent (MySQL prod / SQLite dev+test), `App\Archive\ArchiveInspector` + `CoverGenerator` (Plan 3), `App\Parsing\FilenameParser` (Plan 2), ext-zip, ext-gd.

## Global Constraints

- **Framework/PHP:** Laravel 13, PHP 8.3+. Composer platform pinned `8.3.0`; local dev runs 8.5.
- **Broken local toolchain:** prefix EVERY php command with `PATH="/opt/homebrew/opt/php/bin:$PATH"` (working PHP 8.5.4). Env doesn't persist between Bash calls — repeat it. Run tests via `php artisan test`.
- **Avoid `cd` in compound bash** (it has tripped permission prompts); use absolute paths / `git -C`.
- **Commit trailer:** every commit ends with a blank line then exactly:
  `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`
- **PHP style:** single quotes unless interpolation; native typed (readonly promoted) properties over `@var`; `@param`/`@var` only for array-element types PHP can't express; comments in BOTH English and Japanese in the same docblock, short.
- **DB portability:** MySQL (prod) + SQLite (dev/test). Use Eloquent only — no MySQL-only raw SQL. Feature tests use `RefreshDatabase` on in-memory SQLite.
- **Identity (§5):** a work is identified by `content_hash` (from `ArchiveInspector`), never by path. Matching is by hash.
- **§7 matching (locked):** incremental skip if same `relative_path` + unchanged `file_size`+`file_mtime`; else inspect → hash → (hash+same-path: touch size/mtime/last_seen) / (hash+different-path: *moved* — update path/filename/mangaka, **re-parse**, keep `reading_progress`) / (no hash: *new* — parse, set entries+page_count, generate cover, insert).
- **Missing (§7, locked):** works with `last_seen_at < scanStart` → `is_missing = true`; seen works → `is_missing = false`. **Never deleted** (progress preserved).
- **Cover only for new `content_hash`** (keyed by hash; moved/updated reuse it). Works with zero image entries get `cover_path = null`.
- **Mangaka slug (locked):** `Str::slug(name)`, else `'mangaka-'.substr(sha1(name),0,12)` for non-ASCII (e.g. Japanese) names; ensure uniqueness by appending `-2`, `-3`, … on collision. (`mangaka.slug` is unique; `name` is effectively unique per filesystem.)
- **Stats (locked):** `{added, updated, moved, missing, failed}` (concrete form of §5's "added/updated/removed/missing").
- **Trigger (§7):** scanning runs as a **queued job**; the command dispatches it; a scheduled entry dispatches it (default daily). `triggered_by` = `manual`|`scheduled`. One worker processes sequentially.
- **Errors:** per-zip `ArchiveException` → increment `failed`, `report()` it, continue (one bad zip never aborts the scan). A catastrophic failure → the job marks the `Scan` `failed`.
- **Workflow:** TDD, DRY, YAGNI, bite-sized commits. Feature tests build a temp fixture library (`<mangaka>/<doujin>.zip` with real GD images) + temp covers dir, pointing `config('scan.*')` at them.

## File Structure

- `config/scan.php` — paths, image extensions, cover settings, schedule.
- `app/Providers/AppServiceProvider.php` — **modify**: bind `ArchiveInspector`, `CoverGenerator` (Task 1), `LibraryScanner` (Task 2) from config.
- `app/Scanning/LibraryScanner.php` — the scan service (Tasks 2–3).
- `app/Jobs/ScanLibrary.php` — queued job + `scans` lifecycle (Task 4).
- `app/Console/Commands/ScanCommand.php` — `wydoujin:scan` (Task 5).
- `routes/console.php` — **modify**: scheduled scan (Task 5).
- `tests/Feature/Scanning/BuildsLibraryFixtures.php` — shared fixture trait (Task 2).
- `tests/Feature/Scanning/{BindingsTest,LibraryScannerMatchingTest,LibraryScannerMissingTest,ScanLibraryJobTest,ScanCommandTest}.php`.

---

## Task 1: `config/scan.php` + archive-unit bindings

**Files:**
- Create: `config/scan.php`
- Modify: `app/Providers/AppServiceProvider.php` (add bindings in `register()`)
- Test: `tests/Feature/Scanning/BindingsTest.php`

**Interfaces:**
- Consumes: `App\Archive\ArchiveInspector` (`__construct(array $imageExtensions)`), `App\Archive\CoverGenerator` (`__construct(string $coversDir, int $width, int $quality)`).
- Produces: `config('scan.library_path'|'data_path'|'image_extensions'|'cover.width'|'cover.quality'|'schedule')`; `app(ArchiveInspector::class)` built from `config('scan.image_extensions')`; `app(CoverGenerator::class)` built with `coversDir = config('scan.data_path').'/covers'` and the config cover settings.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Scanning/BindingsTest.php`:
```php
<?php

namespace Tests\Feature\Scanning;

use App\Archive\ArchiveInspector;
use App\Archive\CoverGenerator;
use Tests\TestCase;

class BindingsTest extends TestCase
{
    public function test_scan_config_has_expected_keys(): void
    {
        $this->assertIsArray(config('scan.image_extensions'));
        $this->assertContains('jpg', config('scan.image_extensions'));
        $this->assertNotEmpty(config('scan.library_path'));
        $this->assertNotEmpty(config('scan.data_path'));
        $this->assertIsInt(config('scan.cover.width'));
        $this->assertIsInt(config('scan.cover.quality'));
    }

    public function test_archive_units_resolve_from_container(): void
    {
        $this->assertInstanceOf(ArchiveInspector::class, app(ArchiveInspector::class));
        $this->assertInstanceOf(CoverGenerator::class, app(CoverGenerator::class));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=BindingsTest`
Expected: FAIL (`config('scan.*')` is null; assertions fail).

- [ ] **Step 3: Create `config/scan.php`**

`config/scan.php`:
```php
<?php

return [
    // Where the library lives (mounted read-only in prod). / ライブラリの場所。
    'library_path' => env('LIBRARY_PATH', '/library'),

    // Writable data root; covers go in <data_path>/covers. / 書き込み可能データ領域。
    'data_path' => env('DATA_PATH', '/data'),

    // Indexed image extensions (lowercase). / 索引対象の画像拡張子。
    'image_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'],

    'cover' => [
        'width' => (int) env('SCAN_COVER_WIDTH', 400),
        'quality' => (int) env('SCAN_COVER_QUALITY', 80),
    ],
];
```

- [ ] **Step 4: Add bindings to `AppServiceProvider::register()`**

In `app/Providers/AppServiceProvider.php`, add these imports after the namespace:
```php
use App\Archive\ArchiveInspector;
use App\Archive\CoverGenerator;
```
Inside `register()` (keep existing bindings like `FilenameParser` intact), append:
```php
$this->app->singleton(ArchiveInspector::class, fn () => new ArchiveInspector(
    config('scan.image_extensions'),
));

$this->app->singleton(CoverGenerator::class, fn () => new CoverGenerator(
    config('scan.data_path').'/covers',
    config('scan.cover.width'),
    config('scan.cover.quality'),
));
```

- [ ] **Step 5: Run test to verify it passes**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=BindingsTest`
Expected: PASS. Then full suite once (`PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test`) — all green.

- [ ] **Step 6: Commit**

```bash
git add config/scan.php app/Providers/AppServiceProvider.php tests/Feature/Scanning/BindingsTest.php
git commit -m "$(cat <<'EOF'
feat: add scan config and bind archive units from it

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: `LibraryScanner` — walk, mangaka upsert, per-zip matching

**Files:**
- Create: `app/Scanning/LibraryScanner.php`
- Modify: `app/Providers/AppServiceProvider.php` (bind `LibraryScanner`)
- Create: `tests/Feature/Scanning/BuildsLibraryFixtures.php` (trait)
- Test: `tests/Feature/Scanning/LibraryScannerMatchingTest.php`

**Interfaces:**
- Consumes: `ArchiveInspector::inspect(string $zipPath): ArchiveInspection{contentHash, imageEntries, pageCount}`; `CoverGenerator::generate(string $zipPath, string $entryName, string $contentHash): string`; `FilenameParser::parse(string $filename, string $mangaka): ParsedName{title,titleRaw,sortTitle,event,circle,author,parody,language,flags}`; models `Mangaka`, `Work`.
- Produces: `App\Scanning\LibraryScanner` — `__construct(ArchiveInspector, CoverGenerator, FilenameParser, string $libraryPath)`; `scan(): array` returning at this stage `['added'=>int,'updated'=>int,'moved'=>int]` (Task 3 adds `missing`/`failed`). `app(LibraryScanner::class)` resolves with `config('scan.library_path')`.

- [ ] **Step 1: Write the fixtures trait**

`tests/Feature/Scanning/BuildsLibraryFixtures.php`:
```php
<?php

namespace Tests\Feature\Scanning;

use ZipArchive;

/** Builds a temp library of <mangaka>/<doujin>.zip with real GD images. / テスト用ライブラリ生成。 */
trait BuildsLibraryFixtures
{
    private string $libraryPath;
    private string $dataPath;

    private function bootLibrary(): void
    {
        $this->libraryPath = sys_get_temp_dir().'/wyd-lib-'.uniqid();
        $this->dataPath = sys_get_temp_dir().'/wyd-data-'.uniqid();
        mkdir($this->libraryPath, 0775, true);
        mkdir($this->dataPath, 0775, true);
        config(['scan.library_path' => $this->libraryPath, 'scan.data_path' => $this->dataPath]);
    }

    private function cleanLibrary(): void
    {
        foreach ([$this->libraryPath ?? null, $this->dataPath ?? null] as $dir) {
            if ($dir !== null && is_dir($dir)) {
                $this->rrmdir($dir);
            }
        }
    }

    private function rrmdir(string $dir): void
    {
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = $dir.'/'.$f;
            is_dir($p) ? $this->rrmdir($p) : unlink($p);
        }
        rmdir($dir);
    }

    /**
     * Create <mangaka>/<filename>.zip with real PNG image entries; return its absolute path.
     * @param string[] $imageEntries
     */
    private function makeDoujin(string $mangaka, string $filename, array $imageEntries = ['001.jpg', '002.jpg']): string
    {
        $dir = $this->libraryPath.'/'.$mangaka;
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = $dir.'/'.$filename.'.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($imageEntries as $entry) {
            $zip->addFromString($entry, $this->pngBytes());
        }
        $zip->close();

        return $path;
    }

    private function pngBytes(): string
    {
        $img = imagecreatetruecolor(20, 30);
        imagefill($img, 0, 0, imagecolorallocate($img, 10, 20, 30));
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }
}
```

- [ ] **Step 2: Write the failing matching test**

`tests/Feature/Scanning/LibraryScannerMatchingTest.php`:
```php
<?php

namespace Tests\Feature\Scanning;

use App\Models\ReadingProgress;
use App\Models\Work;
use App\Scanning\LibraryScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LibraryScannerMatchingTest extends TestCase
{
    use RefreshDatabase;
    use BuildsLibraryFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLibrary();
    }

    protected function tearDown(): void
    {
        $this->cleanLibrary();
        parent::tearDown();
    }

    private function scanner(): LibraryScanner
    {
        return app(LibraryScanner::class);
    }

    public function test_fresh_scan_creates_works_with_parsed_metadata_and_cover(): void
    {
        $this->makeDoujin('Z.A.P.', '(C89) [Z.A.P. (ズッキーニ)] 四畳半物語 (オリジナル) [DL版]');

        $stats = $this->scanner()->scan();

        $this->assertSame(1, $stats['added']);
        $work = Work::firstOrFail();
        $this->assertSame('四畳半物語', $work->title);
        $this->assertSame('C89', $work->event);
        $this->assertSame('Z.A.P.', $work->circle);
        $this->assertSame('ズッキーニ', $work->author);
        $this->assertSame('オリジナル', $work->parody);
        $this->assertSame(['DL版'], $work->flags);
        $this->assertSame(2, $work->page_count);
        $this->assertSame(['001.jpg', '002.jpg'], $work->entries);
        $this->assertSame('Z.A.P.', $work->mangaka->name);
        $this->assertNotEmpty($work->mangaka->slug);
        $this->assertNotNull($work->cover_path);
        $this->assertFileExists($this->dataPath.'/'.$work->cover_path);
    }

    public function test_japanese_mangaka_folder_gets_nonempty_slug(): void
    {
        $this->makeDoujin('ズッキーニ', 'タイトル');

        $this->scanner()->scan();

        $work = Work::firstOrFail();
        $this->assertSame('ズッキーニ', $work->mangaka->name);
        $this->assertNotEmpty($work->mangaka->slug);
        $this->assertStringStartsWith('mangaka-', $work->mangaka->slug);
    }

    public function test_rescan_unchanged_file_is_skipped(): void
    {
        $this->makeDoujin('Circle', 'Title');
        $first = $this->scanner()->scan();
        $this->assertSame(1, $first['added']);

        $second = $this->scanner()->scan();
        $this->assertSame(0, $second['added']);
        $this->assertSame(0, $second['updated']);
        $this->assertSame(0, $second['moved']);
        $this->assertSame(1, Work::count());
    }

    public function test_moved_file_keeps_reading_progress(): void
    {
        $path = $this->makeDoujin('OldCircle', 'Title');
        $this->scanner()->scan();
        $work = Work::firstOrFail();
        ReadingProgress::create(['work_id' => $work->id, 'current_page' => 7]);

        // Move the zip to a different mangaka folder (same bytes → same content_hash).
        $newDir = $this->libraryPath.'/NewCircle';
        mkdir($newDir, 0775, true);
        rename($path, $newDir.'/Title.zip');

        $stats = $this->scanner()->scan();

        $this->assertSame(1, $stats['moved']);
        $this->assertSame(1, Work::count());
        $work->refresh();
        $this->assertSame('NewCircle/Title.zip', $work->relative_path);
        $this->assertSame('NewCircle', $work->mangaka->name);
        $this->assertSame(7, $work->readingProgress->current_page); // progress preserved
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=LibraryScannerMatchingTest`
Expected: FAIL (`App\Scanning\LibraryScanner` not found).

- [ ] **Step 4: Write `LibraryScanner`**

`app/Scanning/LibraryScanner.php`:
```php
<?php

namespace App\Scanning;

use App\Archive\ArchiveInspector;
use App\Archive\CoverGenerator;
use App\Models\Mangaka;
use App\Models\Work;
use App\Parsing\FilenameParser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Walks the library and syncs works into the DB by content_hash. / ライブラリを走査しworksを同期。
 */
final class LibraryScanner
{
    public function __construct(
        private readonly ArchiveInspector $inspector,
        private readonly CoverGenerator $covers,
        private readonly FilenameParser $parser,
        private readonly string $libraryPath,
    ) {
    }

    /** @return array<string,int> stats (added, updated, moved) */
    public function scan(): array
    {
        $stats = ['added' => 0, 'updated' => 0, 'moved' => 0];
        $scanStart = Carbon::now();

        foreach ($this->mangakaFolders() as $folder) {
            $mangaka = $this->resolveMangaka(basename($folder));
            foreach (glob($folder.'/*.zip') ?: [] as $zipPath) {
                $this->processZip($zipPath, $mangaka, $scanStart, $stats);
            }
        }

        return $stats;
    }

    /** @return string[] absolute paths of top-level mangaka folders */
    private function mangakaFolders(): array
    {
        return glob($this->libraryPath.'/*', GLOB_ONLYDIR) ?: [];
    }

    private function resolveMangaka(string $name): Mangaka
    {
        $existing = Mangaka::where('name', $name)->first();
        if ($existing !== null) {
            return $existing;
        }

        return Mangaka::create(['name' => $name, 'slug' => $this->uniqueSlug($name)]);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'mangaka-'.substr(sha1($name), 0, 12);
        }
        $slug = $base;
        $n = 2;
        while (Mangaka::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    /** @param array<string,int> $stats */
    private function processZip(string $zipPath, Mangaka $mangaka, Carbon $scanStart, array &$stats): void
    {
        $relativePath = substr($zipPath, strlen($this->libraryPath) + 1);
        $size = (int) filesize($zipPath);
        $mtime = (int) filemtime($zipPath);

        // Fast incremental skip: same path, unchanged size + mtime. / 高速スキップ。
        $atPath = Work::where('relative_path', $relativePath)->first();
        if ($atPath !== null && (int) $atPath->file_size === $size && (int) $atPath->file_mtime === $mtime) {
            $atPath->update(['last_seen_at' => $scanStart, 'is_missing' => false]);

            return;
        }

        $inspection = $this->inspector->inspect($zipPath);
        $parsed = $this->parser->parse(pathinfo($zipPath, PATHINFO_FILENAME), $mangaka->name);

        $attributes = [
            'mangaka_id' => $mangaka->id,
            'relative_path' => $relativePath,
            'filename' => basename($zipPath),
            'title' => $parsed->title,
            'title_raw' => $parsed->titleRaw,
            'sort_title' => $parsed->sortTitle,
            'event' => $parsed->event,
            'circle' => $parsed->circle,
            'author' => $parsed->author,
            'parody' => $parsed->parody,
            'language' => $parsed->language,
            'flags' => $parsed->flags,
            'page_count' => $inspection->pageCount,
            'entries' => $inspection->imageEntries,
            'file_size' => $size,
            'file_mtime' => $mtime,
            'last_seen_at' => $scanStart,
            'is_missing' => false,
        ];

        $byHash = Work::where('content_hash', $inspection->contentHash)->first();
        if ($byHash !== null) {
            $moved = $byHash->relative_path !== $relativePath;
            $byHash->update($attributes); // keeps content_hash + reading_progress (separate row)
            $stats[$moved ? 'moved' : 'updated']++;

            return;
        }

        $attributes['content_hash'] = $inspection->contentHash;
        $attributes['cover_path'] = $inspection->imageEntries === []
            ? null
            : $this->covers->generate($zipPath, $inspection->imageEntries[0], $inspection->contentHash);

        Work::create($attributes);
        $stats['added']++;
    }
}
```

- [ ] **Step 5: Bind `LibraryScanner` in `AppServiceProvider::register()`**

Add the import:
```php
use App\Parsing\FilenameParser;
use App\Scanning\LibraryScanner;
```
Append to `register()`:
```php
$this->app->bind(LibraryScanner::class, fn ($app) => new LibraryScanner(
    $app->make(ArchiveInspector::class),
    $app->make(CoverGenerator::class),
    $app->make(FilenameParser::class),
    config('scan.library_path'),
));
```

- [ ] **Step 6: Run test to verify it passes**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=LibraryScannerMatchingTest`
Expected: PASS (4 tests). Then full suite once — all green, pristine.

- [ ] **Step 7: Commit**

```bash
git add app/Scanning/LibraryScanner.php app/Providers/AppServiceProvider.php tests/Feature/Scanning/BuildsLibraryFixtures.php tests/Feature/Scanning/LibraryScannerMatchingTest.php
git commit -m "$(cat <<'EOF'
feat: add LibraryScanner walk + content_hash matching (new/skip/moved/updated)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: `LibraryScanner` — missing sweep, per-zip errors, final stats

**Files:**
- Modify: `app/Scanning/LibraryScanner.php` (`scan()` only)
- Test: `tests/Feature/Scanning/LibraryScannerMissingTest.php`

**Interfaces:**
- Consumes: the Task 2 `LibraryScanner`; `App\Archive\ArchiveException`.
- Produces: `scan(): array` now returns `['added'=>int,'updated'=>int,'moved'=>int,'missing'=>int,'failed'=>int]`. Works unseen this scan are flagged `is_missing=true`; a per-zip `ArchiveException` increments `failed` and the scan continues.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Scanning/LibraryScannerMissingTest.php`:
```php
<?php

namespace Tests\Feature\Scanning;

use App\Models\ReadingProgress;
use App\Models\Work;
use App\Scanning\LibraryScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LibraryScannerMissingTest extends TestCase
{
    use RefreshDatabase;
    use BuildsLibraryFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLibrary();
    }

    protected function tearDown(): void
    {
        $this->cleanLibrary();
        parent::tearDown();
    }

    public function test_removed_file_is_flagged_missing_and_keeps_progress(): void
    {
        $path = $this->makeDoujin('Circle', 'Title');
        app(LibraryScanner::class)->scan();
        $work = Work::firstOrFail();
        ReadingProgress::create(['work_id' => $work->id, 'current_page' => 3]);

        unlink($path); // file disappears
        $stats = app(LibraryScanner::class)->scan();

        $this->assertSame(1, $stats['missing']);
        $work->refresh();
        $this->assertTrue($work->is_missing);
        $this->assertSame(3, $work->readingProgress->current_page); // never deleted
    }

    public function test_reappeared_file_is_unflagged(): void
    {
        $this->makeDoujin('Circle', 'Title', ['001.jpg']);
        app(LibraryScanner::class)->scan();
        $work = Work::firstOrFail();
        $work->update(['is_missing' => true]); // simulate previously missing

        $stats = app(LibraryScanner::class)->scan();

        $work->refresh();
        $this->assertFalse($work->is_missing);
        $this->assertSame(0, $stats['missing']);
    }

    public function test_corrupt_zip_increments_failed_and_scan_continues(): void
    {
        $this->makeDoujin('Circle', 'Good', ['001.jpg']);
        // A .zip that is not a valid archive.
        file_put_contents($this->libraryPath.'/Circle/Bad.zip', 'not a zip');

        $stats = app(LibraryScanner::class)->scan();

        $this->assertSame(1, $stats['added']);  // the good one
        $this->assertSame(1, $stats['failed']); // the bad one
        $this->assertSame(1, Work::count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=LibraryScannerMissingTest`
Expected: FAIL (`$stats['missing']`/`['failed']` undefined; corrupt zip throws and aborts).

- [ ] **Step 3: Modify `scan()` in `app/Scanning/LibraryScanner.php`**

Add the import at the top (with the others):
```php
use App\Archive\ArchiveException;
```
Replace the `scan()` method body with:
```php
    /** @return array<string,int> stats (added, updated, moved, missing, failed) */
    public function scan(): array
    {
        $stats = ['added' => 0, 'updated' => 0, 'moved' => 0, 'missing' => 0, 'failed' => 0];
        $scanStart = Carbon::now();

        foreach ($this->mangakaFolders() as $folder) {
            $mangaka = $this->resolveMangaka(basename($folder));
            foreach (glob($folder.'/*.zip') ?: [] as $zipPath) {
                try {
                    $this->processZip($zipPath, $mangaka, $scanStart, $stats);
                } catch (ArchiveException $e) {
                    $stats['failed']++;
                    report($e); // log and continue / 記録して継続
                }
            }
        }

        // Missing sweep: works not seen this scan. / 未検出のworksをmissingに。
        $stats['missing'] = Work::where('last_seen_at', '<', $scanStart)
            ->where('is_missing', false)
            ->update(['is_missing' => true]);

        return $stats;
    }
```
(Leave `mangakaFolders`, `resolveMangaka`, `uniqueSlug`, and `processZip` unchanged from Task 2.)

- [ ] **Step 4: Run test to verify it passes**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=LibraryScannerMissingTest`
Expected: PASS (3 tests). Then re-run `--filter=LibraryScannerMatchingTest` (still green with the new stat keys), then the full suite — all green, pristine.

- [ ] **Step 5: Commit**

```bash
git add app/Scanning/LibraryScanner.php tests/Feature/Scanning/LibraryScannerMissingTest.php
git commit -m "$(cat <<'EOF'
feat: add missing-sweep + per-zip error handling to LibraryScanner

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: `ScanLibrary` queued job (scans lifecycle)

**Files:**
- Create: `app/Jobs/ScanLibrary.php`
- Test: `tests/Feature/Scanning/ScanLibraryJobTest.php`

**Interfaces:**
- Consumes: `App\Scanning\LibraryScanner::scan(): array`; model `App\Models\Scan`.
- Produces: `App\Jobs\ScanLibrary implements ShouldQueue` — `__construct(string $triggeredBy = 'manual')`; `handle(LibraryScanner $scanner): void` creates a `Scan` (status `running`), runs the scan, records `status=completed` + `stats` + `finished_at`; on any `Throwable` records `status=failed` + `finished_at` (does NOT re-throw — avoids retry-spamming failed `Scan` rows).

- [ ] **Step 1: Write the failing test**

`tests/Feature/Scanning/ScanLibraryJobTest.php`:
```php
<?php

namespace Tests\Feature\Scanning;

use App\Jobs\ScanLibrary;
use App\Models\Scan;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScanLibraryJobTest extends TestCase
{
    use RefreshDatabase;
    use BuildsLibraryFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLibrary();
    }

    protected function tearDown(): void
    {
        $this->cleanLibrary();
        parent::tearDown();
    }

    public function test_job_records_a_completed_scan_with_stats(): void
    {
        $this->makeDoujin('Circle', 'Title', ['001.jpg']);

        (new ScanLibrary('manual'))->handle(app(\App\Scanning\LibraryScanner::class));

        $scan = Scan::firstOrFail();
        $this->assertSame('completed', $scan->status);
        $this->assertSame('manual', $scan->triggered_by);
        $this->assertSame(1, $scan->stats['added']);
        $this->assertNotNull($scan->started_at);
        $this->assertNotNull($scan->finished_at);
        $this->assertSame(1, Work::count());
    }

    public function test_job_records_a_failed_scan_when_the_scanner_throws(): void
    {
        // Mock the scanner to throw, exercising the job's Throwable → failed path.
        $this->mock(\App\Scanning\LibraryScanner::class, function ($mock) {
            $mock->shouldReceive('scan')->once()->andThrow(new \RuntimeException('boom'));
        });

        (new ScanLibrary('scheduled'))->handle(app(\App\Scanning\LibraryScanner::class));

        $scan = Scan::firstOrFail();
        $this->assertSame('failed', $scan->status);
        $this->assertSame('scheduled', $scan->triggered_by);
        $this->assertNotNull($scan->finished_at);
        $this->assertSame('boom', $scan->stats['error']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=ScanLibraryJobTest`
Expected: FAIL (`App\Jobs\ScanLibrary` not found).

- [ ] **Step 3: Write `ScanLibrary`**

`app/Jobs/ScanLibrary.php`:
```php
<?php

namespace App\Jobs;

use App\Models\Scan;
use App\Scanning\LibraryScanner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/** Queued library scan; owns the scans-row lifecycle. / キュー実行のスキャン。scans行のライフサイクル管理。 */
final class ScanLibrary implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $triggeredBy = 'manual')
    {
    }

    public function handle(LibraryScanner $scanner): void
    {
        $scan = Scan::create([
            'status' => 'running',
            'triggered_by' => $this->triggeredBy,
            'started_at' => now(),
        ]);

        try {
            $stats = $scanner->scan();
            $scan->update(['status' => 'completed', 'stats' => $stats, 'finished_at' => now()]);
        } catch (Throwable $e) {
            // Record the failure; do not re-throw (avoids retry-spamming failed scan rows).
            $scan->update(['status' => 'failed', 'stats' => ['error' => $e->getMessage()], 'finished_at' => now()]);
            report($e);
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=ScanLibraryJobTest`
Expected: PASS. Full suite once — all green.

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/ScanLibrary.php tests/Feature/Scanning/ScanLibraryJobTest.php
git commit -m "$(cat <<'EOF'
feat: add ScanLibrary queued job with scans lifecycle

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: `wydoujin:scan` command + scheduled scan

**Files:**
- Create: `app/Console/Commands/ScanCommand.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Scanning/ScanCommandTest.php`

**Interfaces:**
- Consumes: `App\Jobs\ScanLibrary`.
- Produces: `php artisan wydoujin:scan` dispatches `ScanLibrary('manual')` to the queue; `routes/console.php` schedules `ScanLibrary('scheduled')` daily.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Scanning/ScanCommandTest.php`:
```php
<?php

namespace Tests\Feature\Scanning;

use App\Jobs\ScanLibrary;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ScanCommandTest extends TestCase
{
    public function test_command_dispatches_a_manual_scan_job(): void
    {
        Queue::fake();

        $this->artisan('wydoujin:scan')->assertSuccessful();

        Queue::assertPushed(ScanLibrary::class, function (ScanLibrary $job) {
            return $job->triggeredBy === 'manual';
        });
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=ScanCommandTest`
Expected: FAIL (command `wydoujin:scan` not defined).

- [ ] **Step 3: Write `ScanCommand`**

`app/Console/Commands/ScanCommand.php`:
```php
<?php

namespace App\Console\Commands;

use App\Jobs\ScanLibrary;
use Illuminate\Console\Command;

/** Queue a manual library scan. / 手動のライブラリスキャンをキューに入れる。 */
final class ScanCommand extends Command
{
    protected $signature = 'wydoujin:scan';
    protected $description = 'Queue a scan of the library';

    public function handle(): int
    {
        ScanLibrary::dispatch('manual');
        $this->info('Library scan queued.');

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=ScanCommandTest`
Expected: PASS.

- [ ] **Step 5: Schedule the periodic scan**

Append to `routes/console.php`:
```php
use App\Jobs\ScanLibrary;
use Illuminate\Support\Facades\Schedule;

// Periodic library scan (the s6 scheduler runs `schedule:work`). / 定期スキャン。
Schedule::job(new ScanLibrary('scheduled'))->daily();
```

- [ ] **Step 6: Verify the schedule is registered**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan schedule:list`
Expected: lists a daily entry for the `ScanLibrary` job (no errors).

- [ ] **Step 7: Run the full suite**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test`
Expected: all green, pristine.

- [ ] **Step 8: Commit**

```bash
git add app/Console/Commands/ScanCommand.php routes/console.php tests/Feature/Scanning/ScanCommandTest.php
git commit -m "$(cat <<'EOF'
feat: add wydoujin:scan command and daily scheduled scan

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Self-Review Notes

- **Spec §7 coverage:** walk `/library/<mangaka>/*.zip`, top dir = mangaka (Task 2 `mangakaFolders`/`resolveMangaka`); only `.zip` (`glob('*.zip')`); fast incremental skip on path+size+mtime (Task 2 `processZip`); inspect → content_hash → hash-match new/moved/updated (Task 2); new works parse + entries + page_count + cover (Task 2); missing sweep, never deleted (Task 3); per-zip error → continue (Task 3); scans stats + lifecycle (Task 4); queued job + manual command + scheduled (Tasks 4–5); one sequential worker (the s6 `worker` service from Plan 1 — no concurrency, so the slug/cover paths need no locking).
- **§11 scanner feature tests:** rows created + parsed fields + cover (Task 2 `test_fresh_scan...`), incremental skip (Task 2 `test_rescan_unchanged...`), rename-preserves-progress (Task 2 `test_moved_file_keeps_reading_progress`), missing flagged + progress kept (Task 3), cover generated (Task 2). Built on a temp fixture library with real GD images.
- **Type consistency:** `LibraryScanner::scan(): array` stat keys grow from `{added,updated,moved}` (Task 2) to `{+missing,+failed}` (Task 3) — both the matching test and missing test assert the keys present at their stage. `ScanLibrary::__construct(string $triggeredBy)` + the public `$triggeredBy` prop are used by the command (Task 5) and the job test (Task 4). `ArchiveInspection`/`ParsedName`/`CoverGenerator::generate` signatures match Plans 2–3.
- **content_hash collision caveat:** two distinct zips with identical entry names+sizes hash equal; the second is treated as a "move" of the first. This is the documented §5 identity tradeoff; acceptable and rare. Noted, not handled.
- **No placeholders:** every step has complete code + an exact command. The fixtures trait builds real PNG images (no `imagedestroy` — deprecated on PHP 8.5).
