# Full Rescan Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a Maintenance "Full Rescan" action that wipes all derived + curated metadata (tags, rename/merge tombstones, series, curation locks, cover cache) and re-runs a forced library scan that re-derives everything from filenames, bypassing the incremental fast-skip.

**Architecture:** A `force` boolean is threaded through the existing scan pipeline (`TriggerScan → ScanLibrary → ScannerContract::planJobs → ProcessZip`). When set, `ScanLibrary` first runs a new `MetadataReset` service (the clean-slate wipe), then fans out `ProcessZip` tasks that skip the unchanged-file fast-skip and re-render covers for existing works too. The web layer adds one route + controller method + a confirm-dialog UI; everything else reuses the current scan plumbing and status polling.

**Tech Stack:** Laravel 13 (PHP 8.5 locally), Pest 4 on in-memory SQLite, Blade + Alpine.js, vendored Apple Design System tokens.

## Global Constraints

- **Local toolchain:** prefix every `php`/`artisan`/`composer` command with `PATH="/opt/homebrew/opt/php/bin:$PATH"` (PHP 8.5). Node/npm are on the normal PATH.
- **Test command:** `PATH="/opt/homebrew/opt/php/bin:$PATH" vendor/bin/pest` (full suite) or `... vendor/bin/pest --filter='<name>'` (one test). Browser suite is explicit: `... vendor/bin/pest tests/Browser`.
- **Identity is `content_hash`, never path.** Works are NEVER deleted by this feature — only their derived columns/locks are cleared — so `content_hash` identity and `reading_progress` survive.
- **DB portability:** MySQL (prod) / SQLite (dev+tests). No `TRUNCATE`, no raw SQL, no MySQL-only types. Use the query builder / Eloquent.
- **Design tokens only:** never inline a raw hex or px size — reference a token (`var(--surface-card)`, `var(--radius-lg)`, `var(--type-lead)`, `var(--color-error)`, …). Weight ladder is 300/400/600/700 (no 500). Dark mode inherits via tokens — no component-level dark code.
- **Alpine.js is the only JS library.** No SPA framework, no jQuery. Use a native (non-`confirm()`) styled dialog.
- **Coverage:** project standard is 100% line coverage of `app/` (PCOV, off by default): `PATH="/opt/homebrew/opt/php/bin:$PATH" php -d pcov.enabled=1 vendor/bin/pest --coverage`.
- **Commits:** small, logical, descriptive. End commit messages with the repo's `Co-Authored-By` / `Claude-Session` trailers.

---

### Task 1: `force` flag on `ProcessZip` (bypass fast-skip + re-render existing covers)

The normal scan fast-skips a file whose path+size+mtime are unchanged, and dispatches a cover only for newly-`added` works. A forced rescan must (a) always re-inspect + re-tag, and (b) re-render covers for existing (`updated`/`moved`) works too, since the cache was just cleared.

**Files:**
- Modify: `app/Jobs/ProcessZip.php`
- Test: `tests/Feature/Scanning/ProcessZipTest.php`

**Interfaces:**
- Produces: `ProcessZip::__construct(int $scanId, int $mangakaId, string $mangakaName, string $zipPath, string $relativePath, string $scanStartIso, bool $force = false)` with a `public readonly bool $force`.

- [ ] **Step 1: Write the failing tests** — append to `tests/Feature/Scanning/ProcessZipTest.php`:

```php
test('a forced ProcessZip re-derives an unchanged file and re-renders its cover', function (): void {
    $this->makeDoujin('Circle', 'Title', ['001.jpg']);
    $mangaka = Mangaka::create(['name' => 'Circle', 'slug' => 'circle']);
    $scan = Scan::create(['status' => 'running', 'triggered_by' => 'full', 'started_at' => now()]);
    $path = $this->libraryPath.'/Circle/Title.zip';

    // First pass adds the work (and its cover).
    Bus::fake();
    runProcessZip(new ProcessZip($scan->id, $mangaka->id, $mangaka->name, $path, 'Circle/Title.zip', now()->toIso8601String()));
    $work = Work::firstOrFail();

    // Second pass, file unchanged, force = true: NOT skipped — re-derived as 'updated' + cover re-dispatched.
    Bus::fake();
    runProcessZip(new ProcessZip($scan->id, $mangaka->id, $mangaka->name, $path, 'Circle/Title.zip', now()->toIso8601String(), true));

    Bus::assertDispatched(GenerateCover::class, fn (GenerateCover $g) => $g->workId === $work->id);
    $this->assertSame(1, (int) $scan->refresh()->updated);
});

test('without force an unchanged file is still fast-skipped', function (): void {
    $this->makeDoujin('Circle', 'Title', ['001.jpg']);
    $mangaka = Mangaka::create(['name' => 'Circle', 'slug' => 'circle']);
    $scan = Scan::create(['status' => 'running', 'triggered_by' => 'manual', 'started_at' => now()]);
    $path = $this->libraryPath.'/Circle/Title.zip';

    Bus::fake();
    runProcessZip(new ProcessZip($scan->id, $mangaka->id, $mangaka->name, $path, 'Circle/Title.zip', now()->toIso8601String()));

    Bus::fake();
    runProcessZip(new ProcessZip($scan->id, $mangaka->id, $mangaka->name, $path, 'Circle/Title.zip', now()->toIso8601String()));

    Bus::assertNotDispatched(GenerateCover::class); // second pass skipped
    $this->assertSame(0, (int) $scan->refresh()->updated);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" vendor/bin/pest --filter='forced ProcessZip'`
Expected: FAIL — the 7th constructor argument is rejected / cover not dispatched on the existing path.

- [ ] **Step 3: Implement the `force` flag in `app/Jobs/ProcessZip.php`**

Add the constructor param (after `$scanStartIso`):

```php
        public readonly string $scanStartIso,
        public readonly bool $force = false,
    ) {
    }
```

Guard the fast-skip (in `process()`):

```php
        // Fast incremental skip: same path, unchanged size + mtime — unless this is a forced
        // rescan, which must re-derive every work. / 高速スキップ（強制時は無効）。
        $atPath = Work::where('relative_path', $this->relativePath)->first();
        if (! $this->force && $atPath !== null && (int) $atPath->file_size === $size && (int) $atPath->file_mtime === $mtime) {
            $atPath->update(['last_seen_at' => $scanStart, 'is_missing' => false]);

            return 'skipped';
        }
```

Pass a `$hasImages` flag into both `applyToExisting` calls. Replace the `$byHash` block and the catch block in `process()`:

```php
        $hasImages = $inspection->imageEntries !== [];

        $byHash = Work::where('content_hash', $inspection->contentHash)->first();
        if ($byHash !== null) {
            return $this->applyToExisting($byHash, $attributes, $parsed, $tags, $hasImages);
        }

        $attributes['content_hash'] = $inspection->contentHash;

        try {
            $work = Work::create($attributes);
        } catch (UniqueConstraintViolationException) {
            $byHash = Work::where('content_hash', $inspection->contentHash)->firstOrFail();

            return $this->applyToExisting($byHash, $attributes, $parsed, $tags, $hasImages);
        }

        $tags->sync($work, $parsed); // sync metadata tags / メタデータタグを同期

        if ($hasImages) {
            GenerateCover::dispatch($work->id); // offload cover render / 表紙生成は別タスクへ
        }

        return 'added';
```

Update `applyToExisting` to re-render the cover on a forced rescan:

```php
    private function applyToExisting(Work $work, array $attributes, \App\Parsing\ParsedName $parsed, WorkTagSync $tags, bool $hasImages): string
    {
        $moved = $work->relative_path !== $this->relativePath;
        $work->update($attributes);
        $tags->sync($work, $parsed);

        // A forced rescan cleared the cover cache, so re-render existing works' covers too
        // (the normal scan only covers newly-added works). / 強制時は既存作品の表紙も再生成。
        if ($this->force && $hasImages) {
            GenerateCover::dispatch($work->id);
        }

        return $moved ? 'moved' : 'updated';
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" vendor/bin/pest tests/Feature/Scanning/ProcessZipTest.php`
Expected: PASS (all tests in the file).

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/ProcessZip.php tests/Feature/Scanning/ProcessZipTest.php
git commit -m "Add force flag to ProcessZip: bypass fast-skip + re-render existing covers"
```

---

### Task 2: `MetadataReset` service (the clean-slate wipe)

A standalone, unit-testable service that deletes the cover cache and all derived + curated metadata, keeping work rows (and thus reading progress) intact.

**Files:**
- Create: `app/Scanning/MetadataReset.php`
- Modify: `app/Providers/AppServiceProvider.php` (register the binding)
- Test: `tests/Feature/Scanning/MetadataResetTest.php`

**Interfaces:**
- Produces: `MetadataReset::__construct(string $coversDir)` and `MetadataReset::reset(): void`.

- [ ] **Step 1: Write the failing test** — create `tests/Feature/Scanning/MetadataResetTest.php`:

```php
<?php

use App\Models\Mangaka;
use App\Models\Series;
use App\Models\Tag;
use App\Models\Work;
use App\Scanning\MetadataReset;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Scanning\BuildsLibraryFixtures;

uses(BuildsLibraryFixtures::class);

beforeEach(fn () => $this->bootLibrary());
afterEach(fn () => $this->cleanLibrary());

test('reset wipes tags, tombstones, series and covers but keeps works + progress', function (): void {
    $mangaka = Mangaka::create(['name' => 'Circle', 'slug' => 'circle']);
    $series = Series::create(['mangaka_id' => $mangaka->id, 'name' => 'Saga', 'sort_name' => 'saga', 'is_auto' => false]);

    $canonical = Tag::create(['type' => 'author', 'value' => 'Aoi']);
    $tombstone = Tag::create(['type' => 'author', 'value' => 'Aoy', 'merged_into_id' => $canonical->id]);

    $work = Work::factory()->for($mangaka)->create([
        'content_hash' => 'hash-keepme',
        'cover_path' => 'covers/hash-keepme.webp',
        'tags_locked' => true,
        'series_locked' => true,
        'series_id' => $series->id,
    ]);
    $work->tags()->attach($canonical->id);
    DB::table('reading_progress')->insert(['work_id' => $work->id, 'current_page' => 7, 'is_completed' => false, 'created_at' => now(), 'updated_at' => now()]);

    $coversDir = $this->dataPath.'/covers';
    mkdir($coversDir, 0775, true);
    file_put_contents($coversDir.'/hash-keepme.webp', 'fake');
    file_put_contents($coversDir.'/orphan.webp', 'fake');

    (new MetadataReset($coversDir))->reset();

    // Derived + curated metadata gone.
    expect(Tag::count())->toBe(0);
    expect(DB::table('work_tag')->count())->toBe(0);
    expect(Series::count())->toBe(0);
    expect(glob($coversDir.'/*.webp'))->toBe([]);

    // Work row kept (identity + progress survive), locks + cover_path + series_id cleared.
    $work->refresh();
    expect($work->exists)->toBeTrue();
    expect($work->content_hash)->toBe('hash-keepme');
    expect($work->cover_path)->toBeNull();
    expect((bool) $work->tags_locked)->toBeFalse();
    expect((bool) $work->series_locked)->toBeFalse();
    expect($work->series_id)->toBeNull();
    expect(DB::table('reading_progress')->where('work_id', $work->id)->value('current_page'))->toBe(7);
});

test('reset is a no-op when the covers dir does not exist', function (): void {
    (new MetadataReset($this->dataPath.'/nope'))->reset();

    expect(Tag::count())->toBe(0); // ran cleanly, nothing to delete
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" vendor/bin/pest tests/Feature/Scanning/MetadataResetTest.php`
Expected: FAIL with "Class App\Scanning\MetadataReset not found".

- [ ] **Step 3: Create `app/Scanning/MetadataReset.php`**

```php
<?php

namespace App\Scanning;

use App\Models\Series;
use App\Models\Tag;
use App\Models\Work;
use Illuminate\Support\Facades\DB;

/**
 * Clean-slate wipe for a Full Rescan: deletes every cover file and all derived + curated
 * metadata (tags, the work_tag pivot, rename/merge tombstones, series), and clears each
 * work's cover_path + curation locks. Work rows are kept, so content_hash identity and
 * reading progress survive. / フルスキャン用の全消去（作品行と進捗は保持）。
 */
final class MetadataReset
{
    public function __construct(private readonly string $coversDir)
    {
    }

    public function reset(): void
    {
        // Cover cache: delete outside the transaction — a stray unlink must not roll back the
        // DB wipe. glob() returns false if the dir is missing; treat as nothing to do.
        // 表紙キャッシュ削除（トランザクション外、ディレクトリ無しは何もしない）。
        foreach (glob($this->coversDir.'/*.webp') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        DB::transaction(function (): void {
            // Clear works first so no row references a series we're about to delete (FK-safe).
            // 先に作品を更新し、削除予定のシリーズへの参照を外す。
            Work::query()->update([
                'cover_path' => null,
                'tags_locked' => false,
                'series_locked' => false,
                'series_id' => null,
            ]);
            DB::table('work_tag')->delete();
            Tag::query()->delete();    // merged_into_id is nullOnDelete, so tombstones drop cleanly / 墓石も削除
            Series::query()->delete();
        });
    }
}
```

- [ ] **Step 4: Register the binding in `app/Providers/AppServiceProvider.php`**

Add the import near the other `App\…` uses:

```php
use App\Scanning\MetadataReset;
```

Register it next to the `CoverGenerator` singleton (same covers dir):

```php
        $this->app->singleton(MetadataReset::class, fn () => new MetadataReset(
            config('scan.data_path').'/covers',
        ));
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" vendor/bin/pest tests/Feature/Scanning/MetadataResetTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Scanning/MetadataReset.php app/Providers/AppServiceProvider.php tests/Feature/Scanning/MetadataResetTest.php
git commit -m "Add MetadataReset service: clean-slate wipe of tags, series and cover cache"
```

---

### Task 3: Thread `force` through the scanner + run the reset in `ScanLibrary`

**Files:**
- Modify: `app/Scanning/ScannerContract.php` (signature)
- Modify: `app/Scanning/LibraryScanner.php` (pass force into `ProcessZip`)
- Modify: `app/Jobs/ScanLibrary.php` (constructor `force` + run reset when forced + pass force to `planJobs`)
- Test: `tests/Feature/Scanning/ScanLibraryJobTest.php`

**Interfaces:**
- Consumes: `ProcessZip` 7th arg `bool $force` (Task 1); `MetadataReset::reset()` (Task 2).
- Produces: `ScannerContract::planJobs(int $scanId, string $scanStartIso, bool $force = false): array`; `ScanLibrary::__construct(string $triggeredBy = 'manual', ?int $scanId = null, bool $force = false)` with `public readonly bool $force`.

- [ ] **Step 1: Write the failing tests** — append to `tests/Feature/Scanning/ScanLibraryJobTest.php` (and add `use App\Models\Tag;` + `use App\Scanning\MetadataReset;` to the file's `use` block):

```php
test('a forced scan wipes existing metadata and fans out forced ProcessZip tasks', function (): void {
    Bus::fake();
    $this->makeDoujin('Circle', 'A', ['001.jpg']);
    Tag::create(['type' => 'author', 'value' => 'StaleAuthor']); // pre-existing metadata to be wiped

    $scan = Scan::create(['status' => 'queued', 'triggered_by' => 'full']);
    (new ScanLibrary('full', $scan->id, true))->handle(app(ScannerContract::class), app(MetadataReset::class));

    expect(Tag::count())->toBe(0); // reset ran
    Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 1
        && $batch->jobs->every(fn ($job) => $job instanceof ProcessZip && $job->force === true));
});

test('a normal scan does not run the reset', function (): void {
    Bus::fake();
    $this->makeDoujin('Circle', 'A', ['001.jpg']);
    Tag::create(['type' => 'author', 'value' => 'KeepAuthor']);

    $scan = Scan::create(['status' => 'queued', 'triggered_by' => 'manual']);
    (new ScanLibrary('manual', $scan->id))->handle(app(ScannerContract::class), app(MetadataReset::class));

    expect(Tag::count())->toBe(1); // untouched
    Bus::assertBatched(fn ($batch) => $batch->jobs->every(fn ($job) => $job->force === false));
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" vendor/bin/pest --filter='forced scan wipes'`
Expected: FAIL — `handle()` takes one arg / `ScanLibrary` has no `force` / `$job->force` undefined.

- [ ] **Step 3: Update the contract `app/Scanning/ScannerContract.php`**

```php
    /**
     * Resolve mangaka folders and emit one ProcessZip task per zip. $force flags a full
     * rescan, carried into each ProcessZip (bypass fast-skip + re-render covers).
     *
     * @return list<ProcessZip>
     */
    public function planJobs(int $scanId, string $scanStartIso, bool $force = false): array;
```

- [ ] **Step 4: Update `app/Scanning/LibraryScanner.php`**

Change the `planJobs` signature and pass `force` into each `ProcessZip`:

```php
    public function planJobs(int $scanId, string $scanStartIso, bool $force = false): array
    {
        $jobs = [];
        $mangakaByName = []; // memo: derived name → Mangaka (sequential here, so no create race) / 競合回避メモ
        foreach ($this->zipFiles() as $zipPath) {
            $relativePath = substr($zipPath, strlen($this->libraryPath) + 1);
            $name = $this->resolver->resolve($relativePath)->mangakaName;
            $mangaka = $mangakaByName[$name] ??= $this->resolveMangaka($name);
            $jobs[] = new ProcessZip(
                $scanId,
                $mangaka->id,
                $mangaka->name,
                $zipPath,
                $relativePath,
                $scanStartIso,
                $force,
            );
        }

        return $jobs;
    }
```

- [ ] **Step 5: Update `app/Jobs/ScanLibrary.php`**

Add the import:

```php
use App\Scanning\MetadataReset;
```

Add the constructor param (`public readonly bool $force = false` after `$scanId`):

```php
    public function __construct(
        public readonly string $triggeredBy = 'manual',
        public readonly ?int $scanId = null,
        public readonly bool $force = false,
    ) {
        $this->timeout = (int) config('scan.scan_timeout', 3600);
    }
```

Change `handle` to accept `MetadataReset`, run the wipe when forced (after marking running, before planning), and pass `force` to `planJobs`:

```php
    public function handle(ScannerContract $scanner, MetadataReset $reset): void
    {
        $scan = $this->scanId ? Scan::find($this->scanId) : null;

        if ($scan) {
            $scan->update(['status' => 'running', 'started_at' => now()]);
        } else {
            $scan = Scan::create([
                'status' => 'running',
                'triggered_by' => $this->triggeredBy,
                'started_at' => now(),
            ]);
        }

        $scanId = $scan->id;
        $scanStartIso = $scan->started_at->toIso8601String();

        try {
            // Full rescan: clean-slate wipe before re-deriving everything. / フルスキャンは先に全消去。
            if ($this->force) {
                $reset->reset();
            }

            $jobs = $scanner->planJobs($scanId, $scanStartIso, $this->force);
        } catch (Throwable $e) {
            $scan->update(['status' => 'failed', 'stats' => ['error' => $e->getMessage()], 'finished_at' => now()]);
            report($e);

            return;
        }

        if ($jobs === []) {
            FinalizeScan::dispatch($scanId);

            return;
        }

        Bus::batch($jobs)
            ->name("library-scan:{$scanId}")
            ->allowFailures()
            ->finally([self::class, 'dispatchFinalize'])
            ->dispatch();
    }
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" vendor/bin/pest tests/Feature/Scanning/ScanLibraryJobTest.php`
Expected: PASS (existing tests still pass — they call `handle(app(ScannerContract::class), app(MetadataReset::class))`; update any older calls in this file that pass a single arg).

> Note: the `BuildsLibraryFixtures::runScan()` helper calls `(new ScanLibrary(...))->handle(app(LibraryScanner::class))`. Update it to also pass `app(MetadataReset::class)`:
> `(new ScanLibrary($triggeredBy, $scan->id))->handle(app(LibraryScanner::class), app(MetadataReset::class));`

- [ ] **Step 7: Run the full scanning suite to catch the `handle()` signature change**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" vendor/bin/pest tests/Feature/Scanning`
Expected: PASS. If any test calls `->handle(...)` on `ScanLibrary` with one argument, add `, app(MetadataReset::class)`.

- [ ] **Step 8: Commit**

```bash
git add app/Scanning/ScannerContract.php app/Scanning/LibraryScanner.php app/Jobs/ScanLibrary.php tests/Feature/Scanning
git commit -m "Thread force through the scanner and run MetadataReset on a full rescan"
```

---

### Task 4: `TriggerScan` force param + controller + route

**Files:**
- Modify: `app/Actions/Maintenance/TriggerScan.php`
- Modify: `app/Http/Controllers/MaintenanceController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Maintenance/MaintenanceTest.php`

**Interfaces:**
- Consumes: `ScanLibrary::__construct(..., bool $force)` (Task 3).
- Produces: `TriggerScan::handle(string $triggeredBy = 'manual', bool $force = false): Scan`; route `POST /maintenance/full-rescan` → `MaintenanceController::fullScan`.

- [ ] **Step 1: Write the failing tests** — append to `tests/Feature/Maintenance/MaintenanceTest.php`:

```php
test('full rescan creates a queued full scan and dispatches a forced ScanLibrary', function (): void {
    Queue::fake();

    $this->postJson('/maintenance/full-rescan')->assertStatus(202)
        ->assertJsonPath('scan.status', 'queued')
        ->assertJsonPath('scan.triggered_by', 'full');

    $scan = Scan::firstOrFail();
    Queue::assertPushed(ScanLibrary::class, fn (ScanLibrary $job) =>
        $job->scanId === $scan->id && $job->triggeredBy === 'full' && $job->force === true);
});

test('full rescan does not double dispatch when a scan is active', function (): void {
    Queue::fake();
    $active = Scan::create(['status' => 'running', 'triggered_by' => 'manual', 'started_at' => now()]);

    $this->postJson('/maintenance/full-rescan')->assertStatus(202)->assertJsonPath('scan.id', $active->id);

    $this->assertSame(1, Scan::count());
    Queue::assertNothingPushed();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" vendor/bin/pest --filter='full rescan'`
Expected: FAIL — route `/maintenance/full-rescan` does not exist (404).

- [ ] **Step 3: Add the `force` param to `app/Actions/Maintenance/TriggerScan.php`**

```php
    public function handle(string $triggeredBy = 'manual', bool $force = false): Scan
    {
        $active = Scan::active()->latest()->first();
        if ($active) {
            return $active;
        }

        $scan = Scan::create(['status' => 'queued', 'triggered_by' => $triggeredBy]);
        ScanLibrary::dispatch($triggeredBy, $scan->id, $force);

        return $scan;
    }
```

- [ ] **Step 4: Add the controller method to `app/Http/Controllers/MaintenanceController.php`**

Add below `scan()`:

```php
    public function fullScan(TriggerScan $action)
    {
        // Clean-slate wipe + forced re-derive; the action dedupes against an active scan. / 全消去後に再走査。
        return response()->json(['scan' => $this->serialize($action->handle('full', force: true))], 202);
    }
```

- [ ] **Step 5: Register the route in `routes/web.php`** (next to `POST /scan`)

```php
Route::post('/maintenance/full-rescan', [MaintenanceController::class, 'fullScan'])->name('maintenance.full-rescan');
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" vendor/bin/pest tests/Feature/Maintenance/MaintenanceTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Actions/Maintenance/TriggerScan.php app/Http/Controllers/MaintenanceController.php routes/web.php tests/Feature/Maintenance/MaintenanceTest.php
git commit -m "Add full-rescan action, controller method and route"
```

---

### Task 5: Frontend — Full Rescan button + confirm dialog + shared scan endpoint

**Files:**
- Modify: `resources/views/maintenance/index.blade.php`
- Test: `tests/Feature/Maintenance/MaintenanceTest.php` (server-render assertions)

**Interfaces:**
- Consumes: `POST /maintenance/full-rescan` (Task 4); existing `window.wyd.postJson` + `/maintenance/status` polling.

- [ ] **Step 1: Write the failing render test** — append to `tests/Feature/Maintenance/MaintenanceTest.php`:

```php
test('maintenance page shows the Full Rescan control and its warning copy', function (): void {
    $this->get('/maintenance')->assertOk()
        ->assertSee('Full Rescan')
        ->assertSee("this can't be undone")
        ->assertSee('The entire cover-image cache')
        ->assertSee('Your files and reading progress are kept.');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" vendor/bin/pest --filter='Full Rescan control'`
Expected: FAIL — the strings are not on the page yet.

- [ ] **Step 3: Add the button beside "Scan now"** in `resources/views/maintenance/index.blade.php`

Replace the controls row's button with both buttons:

```blade
            <div class="flex items-center" style="gap:var(--space-md); margin-bottom:var(--space-xl); flex-wrap:wrap;">
                <x-button type="button" x-on:click="scan()" x-bind:disabled="scanning || busy"
                          x-bind:style="(scanning || busy) ? 'opacity:0.5; pointer-events:none;' : ''">▶ Scan now</x-button>
                <x-button type="button" variant="secondary" x-on:click="openFullConfirm()" x-bind:disabled="scanning || busy"
                          x-bind:style="(scanning || busy) ? 'opacity:0.5; pointer-events:none;' : ''">⟳ Full Rescan</x-button>
                <span style="font:var(--type-caption); color:var(--text-muted);" x-text="panelText()"></span>
                <span x-show="error" x-text="error" style="color:var(--color-error); font:var(--type-caption);"></span>
            </div>
```

- [ ] **Step 4: Add the confirm dialog** — place it just inside the `x-data="maintenance(...)"` div, after the controls row:

```blade
            {{-- Full Rescan confirm dialog (destructive, irreversible) --}}
            <div x-show="confirmFull" x-cloak x-transition.opacity
                 class="fixed inset-0 flex items-center justify-center"
                 style="z-index:50; background:rgba(0,0,0,0.45); padding:var(--space-lg);"
                 x-on:click.self="cancelFull()" x-on:keydown.escape.window="cancelFull()">
                <div role="dialog" aria-modal="true"
                     style="background:var(--surface-card); border:1px solid var(--color-hairline); border-radius:var(--radius-lg); max-width:32rem; width:100%; padding:var(--space-xl);">
                    <h2 style="font:var(--type-lead); color:var(--text-heading); margin-bottom:var(--space-sm);">Full Rescan — this can't be undone.</h2>
                    <p style="font:var(--type-body); color:var(--text-muted); margin-bottom:var(--space-sm);">This permanently deletes and rebuilds everything derived from your files:</p>
                    <ul style="font:var(--type-body); color:var(--text-muted); margin:0 0 var(--space-md) var(--space-lg); list-style:disc;">
                        <li>All tags and per-work tag edits</li>
                        <li>All tag renames and merges</li>
                        <li>All series groupings (manual and automatic)</li>
                        <li>The entire cover-image cache</li>
                    </ul>
                    <p style="font:var(--type-body-strong); color:var(--text-heading); margin-bottom:var(--space-sm);">Your files and reading progress are kept.</p>
                    <p style="font:var(--type-caption); color:var(--text-muted); margin-bottom:var(--space-lg);">Everything is then re-derived from your filenames using the current scanning rules.</p>
                    <div class="flex items-center justify-end" style="gap:var(--space-md);">
                        <x-button type="button" variant="secondary" x-on:click="cancelFull()">Cancel</x-button>
                        <x-button type="button" x-on:click="confirmFullRescan()"
                                  style="background:var(--color-error); color:var(--color-on-primary); border:1px solid transparent;">Wipe &amp; rebuild</x-button>
                    </div>
                </div>
            </div>
```

- [ ] **Step 5: Generalise `scan()` and add the dialog methods** in the Alpine `maintenance` component

Add `confirmFull: false,` to the state (next to `busy: false,`). Change `scan()` to accept an endpoint and add the three dialog methods:

```javascript
            async scan(endpoint = '/scan') {
                if (this.scanning || this.busy) return;
                this.busy = true;
                this.error = '';
                try {
                    const data = await window.wyd.postJson(endpoint);
                    this.latest = data.scan;
                    this.startPolling();
                } catch (e) {
                    this.error = 'Could not start scan — try again.';
                } finally {
                    this.busy = false;
                }
            },
            openFullConfirm() { if (!this.scanning && !this.busy) this.confirmFull = true; },
            cancelFull() { this.confirmFull = false; },
            confirmFullRescan() { this.confirmFull = false; this.scan('/maintenance/full-rescan'); },
```

- [ ] **Step 6: Run the render test + the existing maintenance feature tests**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" vendor/bin/pest tests/Feature/Maintenance/MaintenanceTest.php`
Expected: PASS.

- [ ] **Step 7: Build assets to confirm the Blade compiles**

Run: `npm run build`
Expected: Vite build succeeds (no Blade/JS syntax error).

- [ ] **Step 8: Commit**

```bash
git add resources/views/maintenance/index.blade.php tests/Feature/Maintenance/MaintenanceTest.php
git commit -m "Add Full Rescan button + confirm dialog to the maintenance page"
```

---

### Task 6: Browser test for the confirm dialog (explicit suite)

Confirms the Alpine dialog opens, dismisses, and raises no JS errors. Not part of the default suite/CI.

**Files:**
- Modify: `tests/Browser/MaintenanceTest.php`

**Interfaces:**
- Consumes: the rendered dialog + Alpine methods from Task 5.

- [ ] **Step 1: One-time prereq (if not already installed)**

Run: `npm install && npx playwright install chromium`
Expected: Chromium installed.

- [ ] **Step 2: Write the test** — append to `tests/Browser/MaintenanceTest.php`:

```php
test('full rescan button opens and cancels the confirm dialog', function (): void {
    $page = visit('/maintenance');

    $page->assertSee('Full Rescan')
        ->assertDontSee('permanently deletes and rebuilds') // dialog hidden initially
        ->press('Full Rescan')
        ->assertSee('permanently deletes and rebuilds')     // warning now visible
        ->assertSee('Your files and reading progress are kept.')
        ->press('Cancel')
        ->assertDontSee('permanently deletes and rebuilds') // dismissed
        ->assertNoJavaScriptErrors();
});
```

- [ ] **Step 3: Run the browser suite**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" vendor/bin/pest tests/Browser/MaintenanceTest.php`
Expected: PASS, no JavaScript errors. (If `press('Full Rescan')` is ambiguous, the dialog `<h2>` is not a button so it won't match; the button label contains "Full Rescan".)

- [ ] **Step 4: Commit**

```bash
git add tests/Browser/MaintenanceTest.php
git commit -m "Add browser test for the Full Rescan confirm dialog"
```

---

### Final verification

- [ ] **Run the full suite:**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" vendor/bin/pest`
Expected: all green.

- [ ] **Confirm 100% line coverage of the new `app/` code:**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php -d pcov.enabled=1 vendor/bin/pest --coverage --min=100`
Expected: 100% — `MetadataReset`, the `ProcessZip`/`ScanLibrary`/`LibraryScanner` changes, `TriggerScan::handle(force)`, and `MaintenanceController::fullScan` all covered.

---

## Self-Review notes (for the implementer)

- **Spec coverage:** Task 2 = the five-step wipe (covers, pivot, tags+tombstones, series, work locks). Task 1+3 = forced re-derivation bypassing the fast-skip + re-rendering existing covers. Task 4 = `full`-labelled, deduped trigger behind the password gate (the gate is open in tests since `APP_PASSWORD` is unset). Task 5 = the button + the exact warning copy from the spec. Task 6 = the browser check. Series re-detection needs no code — `FinalizeScan` → `SeriesDetector` re-clusters all (now unlocked) works and prunes empty auto-series on its own.
- **Preserved invariants:** works are never deleted (Task 2 only `update`s them), so `content_hash` identity and `reading_progress` survive; FK-safe delete order (works updated → pivot → tags → series); query-builder deletes keep it MySQL/SQLite-portable.
- **Type consistency:** `force` is `public readonly bool` on both `ProcessZip` (7th ctor arg) and `ScanLibrary` (3rd ctor arg); `planJobs(..., bool $force = false)`; `MetadataReset::reset(): void`; `TriggerScan::handle(string, bool): Scan`. `handle()` on `ScanLibrary` now takes `(ScannerContract, MetadataReset)` — every caller (incl. `BuildsLibraryFixtures::runScan`) must pass both.
