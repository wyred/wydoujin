# wydoujin — Page-Serving Backend Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the HTTP backend of spec §9 — three endpoints to read a work: stream a page's image bytes straight from its zip, serve a cached cover by content-hash, and persist per-work reading progress — all behind the existing single-password gate and fully feature-tested.

**Architecture:** A `ZipPageReader` archive unit returns one entry's raw bytes by name (mirrors `CoverGenerator`'s `getFromName` pattern; no disk extraction). `PageController` resolves the in-zip entry from the work's **stored** `entries` list (never re-lists the archive), streams it with an extension-derived content-type and a long-lived ETag keyed on `content_hash`+page (so conditional GETs 304). `CoverController` streams the cached webp from the data root via a hash-constrained route (no path traversal). `ReadingProgressController` upserts the per-work progress row. Routes live in `routes/web.php`, automatically gated by the `RequirePassword` middleware already appended to the `web` group.

**Tech Stack:** Laravel 13 (routes, controllers, route-model binding, validation), `ext-zip` (`ZipArchive::getFromName`), Symfony HTTP (`Response`/`BinaryFileResponse`, ETag/`isNotModified`), Eloquent (MySQL prod / SQLite dev+test).

## Global Constraints

- **Framework/PHP:** Laravel 13, PHP 8.3+. Composer platform pinned `8.3.0`; local dev runs 8.5. No `declare(strict_types=1)` in this codebase (don't add it).
- **Broken local toolchain:** prefix EVERY php command with `PATH="/opt/homebrew/opt/php/bin:$PATH"` (working PHP 8.5.4). Env doesn't persist between Bash calls — repeat it. Run tests via `php artisan test`.
- **Avoid `cd` in compound bash** (it has tripped permission prompts); use absolute paths / `git -C`.
- **Commit trailer:** every commit ends with a blank line then exactly:
  `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`
- **PHP style:** single quotes unless interpolation; native typed (readonly promoted) properties over `@var`; `@param`/`@var`/`@return` only for array shapes PHP can't express; comments in BOTH English and Japanese in the same docblock, short; `final` classes.
- **DB portability:** MySQL (prod) + SQLite (dev/test). Eloquent only — no MySQL-only raw SQL. Feature tests use `RefreshDatabase` on in-memory SQLite.
- **Identity (§5):** a work is identified by `content_hash`, never path. The ETag and the cover URL are keyed on `content_hash`.
- **Never re-list the archive (§9, locked):** the page endpoint takes the in-zip entry name from the work's stored `entries` array (`entries[n-1]`), NOT by re-reading the zip's directory.
- **No disk extraction (§9, locked):** page bytes come from `ZipArchive::getFromName` (in memory); never extract to a temp file.
- **Auth gate (already built):** `RequirePassword` is appended to the `web` group in `bootstrap/app.php` and guards every web path except `login`/`health`. New routes are therefore gated automatically — do NOT add per-route auth middleware. In tests the default env leaves `app.password` null (routes open); `AuthGateTest` already covers the gate mechanism, so these tests don't re-test gating.
- **Workflow:** TDD, DRY, YAGNI, bite-sized commits. One failing test → minimal code → green → commit.

## Scope Decisions (locked for this plan)

1. **This plan is the §9 BACKEND only** — the three endpoints + `ZipPageReader`. The Alpine.js reader UI, the `/work/{work}` detail page that launches it, and every §10 browse surface are the NEXT (frontend) plan: they share the app layout, the design-system Blade/Alpine translation, and site navigation. This plan ships independently testable HTTP endpoints (verifiable with `curl`/feature tests) and unblocks that frontend work.
2. **Page numbering is 1-based.** `n` maps to `entries[n - 1]`. `n < 1`, `n > count(entries)`, a work with no/empty `entries`, or an unopenable/missing zip → **404**.
3. **Page response:** body is the raw entry bytes (loaded into memory via `getFromName` — for MVP single-image pages this matches how covers are read; true chunked streaming and HTTP range requests are deferred). `Content-Type` is set explicitly from the entry's extension (do not rely on content sniffing). `ETag: "<content_hash>-<n>"` + `Cache-Control: public, max-age=31536000, immutable`. A matching `If-None-Match` returns **304** before the zip is even opened (page bytes are immutable for a given content_hash+page).
4. **Covers:** `GET /covers/{hash}.webp`, route-constrained to `[0-9a-f]{64}` (anti-traversal). Serves `config('scan.data_path').'/covers/<hash>.webp'` with an explicit `Content-Type: image/webp` (don't sniff) + immutable cache; **404** if the file is absent. (Cover *generation* already happened in the scan worker — Plan 3.)
4. **Progress:** `POST /work/{work}/progress` with `current_page`. Validation: `required|integer|min:1|max:<work.page_count>` (a work with `page_count = 0` therefore always 422 — you can't read an imageless work). Upsert the unique-per-work `reading_progress` row: set `current_page`; set `started_at` once (first read); set `last_read_at` every time; `is_completed = current_page >= page_count`; `completed_at` = the first-completion time (preserved across re-saves, cleared if un-completed). Returns JSON. The route is in the `web` group, so CSRF applies in production (the later Alpine frontend sends the token); Laravel skips CSRF under tests.
5. **Content-type map:** `jpg`/`jpeg`→`image/jpeg`, `png`→`image/png`, `gif`→`image/gif`, `webp`→`image/webp`, `avif`→`image/avif`; anything else→`application/octet-stream`.

**Deferred (NOT this plan):** the Alpine reader JS (in-place page swap, ←/→ + click zones, preload, RTL/LTR toggle, debounced progress save), the work-detail and browse views (§10), HTTP range requests, and true chunked streaming.

## File Structure

- `app/Archive/ZipPageReader.php` — **create**. `read(string $zipPath, string $entryName): string` → raw bytes; throws `ArchiveException`. Stateless (autowires).
- `app/Http/Controllers/PageController.php` — **create**. `show(Request, Work, int $n, ZipPageReader): Response`.
- `app/Http/Controllers/CoverController.php` — **create**. `show(string $hash): BinaryFileResponse`.
- `app/Http/Controllers/ReadingProgressController.php` — **create**. `update(Request, Work): JsonResponse`.
- `routes/web.php` — **modify**. Add the three routes.
- `tests/Unit/Archive/ZipPageReaderTest.php` — **create** (Task 1).
- `tests/Feature/Reader/ServesReadableWork.php` — **create**. Trait: temp library+data dirs wired into `config('scan.*')`, build a real zip + a `Work` pointing at it (entries match), write a cover file (Task 2).
- `tests/Feature/Reader/PageServingTest.php` — **create** (Task 2).
- `tests/Feature/Reader/CoverServingTest.php` — **create** (Task 3).
- `tests/Feature/Reader/ReadingProgressTest.php` — **create** (Task 4).

**Reference — existing shapes this plan consumes (verbatim, do not re-derive):**
- `App\Archive\CoverGenerator` reads one entry via `$zip->getFromName($entryName)` after `$zip->open($zipPath, ZipArchive::RDONLY)` and throws `App\Archive\ArchiveException` on open/read failure — mirror this in `ZipPageReader`.
- `App\Models\Work` (`$guarded = []`): casts `entries => array`, `page_count => integer`; columns include `content_hash`, `relative_path`, `page_count`, `entries`. Route-model-bound by id.
- `App\Models\ReadingProgress` (`$guarded = []`, table `reading_progress`): casts `is_completed => boolean`, `current_page => integer`, `started_at`/`last_read_at`/`completed_at => datetime`; `work_id` is unique. `belongsTo(Work)`.
- `config('scan.library_path')` (zip root) and `config('scan.data_path')` (covers live at `<data_path>/covers/<hash>.webp`).
- `RequirePassword` middleware is global on the `web` group (exempts `login`/`health`); default test env has `app.password` null.
- `Work::factory()` sets `content_hash` (random sha256), `relative_path`, `filename`, `page_count`, but NOT `entries` (defaults null). `Mangaka::factory()` sets `name`+unique `slug`.
- Test HTTP body: `TestResponse::getContent()` returns the raw response body for a non-streamed `Response`.

---

## Task 1: `ZipPageReader` — read one entry's bytes

**Files:**
- Create: `app/Archive/ZipPageReader.php`
- Test: `tests/Unit/Archive/ZipPageReaderTest.php`

**Interfaces:**
- Consumes: `App\Archive\ArchiveException`; `ext-zip`.
- Produces: `App\Archive\ZipPageReader` — `read(string $zipPath, string $entryName): string` returns the entry's raw bytes; throws `ArchiveException` if the zip can't be opened or the entry can't be read. No constructor (autowires).

- [ ] **Step 1: Write the failing test**

`tests/Unit/Archive/ZipPageReaderTest.php`:
```php
<?php

namespace Tests\Unit\Archive;

use App\Archive\ArchiveException;
use App\Archive\ZipPageReader;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class ZipPageReaderTest extends TestCase
{
    private string $zipPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->zipPath = sys_get_temp_dir().'/wyd-zpr-'.uniqid().'.zip';
        $zip = new ZipArchive();
        $zip->open($this->zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('001.jpg', 'bytes-of-page-one');
        $zip->addFromString('002.png', 'bytes-of-page-two');
        $zip->close();
    }

    protected function tearDown(): void
    {
        @unlink($this->zipPath);
        parent::tearDown();
    }

    public function test_reads_named_entry_bytes(): void
    {
        $reader = new ZipPageReader();

        $this->assertSame('bytes-of-page-one', $reader->read($this->zipPath, '001.jpg'));
        $this->assertSame('bytes-of-page-two', $reader->read($this->zipPath, '002.png'));
    }

    public function test_throws_when_entry_missing(): void
    {
        $this->expectException(ArchiveException::class);
        (new ZipPageReader())->read($this->zipPath, 'nope.jpg');
    }

    public function test_throws_when_zip_cannot_be_opened(): void
    {
        $this->expectException(ArchiveException::class);
        (new ZipPageReader())->read(sys_get_temp_dir().'/wyd-does-not-exist-'.uniqid().'.zip', '001.jpg');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=ZipPageReaderTest`
Expected: FAIL — `Class "App\Archive\ZipPageReader" not found`.

- [ ] **Step 3: Implement `ZipPageReader`**

`app/Archive/ZipPageReader.php`:
```php
<?php

namespace App\Archive;

use ZipArchive;

/**
 * Reads one entry's raw bytes from a zip (in memory; no disk extraction).
 * zip内の1エントリの生バイトを読む（メモリ上、ディスク展開なし）。
 */
final class ZipPageReader
{
    /** @throws ArchiveException if the zip can't be opened or the entry can't be read */
    public function read(string $zipPath, string $entryName): string
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new ArchiveException("Cannot open zip: {$zipPath}");
        }

        $bytes = $zip->getFromName($entryName);
        $zip->close();

        if ($bytes === false) {
            throw new ArchiveException("Cannot read entry {$entryName} in {$zipPath}");
        }

        return $bytes;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=ZipPageReaderTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Archive/ZipPageReader.php tests/Unit/Archive/ZipPageReaderTest.php
git commit -m "$(cat <<'EOF'
feat: add ZipPageReader (read one zip entry's bytes in memory)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: Page-serving endpoint — `GET /work/{work}/page/{n}`

**Files:**
- Create: `app/Http/Controllers/PageController.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/Reader/ServesReadableWork.php` (trait)
- Test: `tests/Feature/Reader/PageServingTest.php`

**Interfaces:**
- Consumes: `App\Archive\ZipPageReader::read()` (Task 1); `App\Archive\ArchiveException`; `App\Models\Work`; `config('scan.library_path')`.
- Produces: route `work.page` → `PageController::show(Request, Work, int $n, ZipPageReader): \Illuminate\Http\Response`. Trait `Tests\Feature\Reader\ServesReadableWork` with `setUpReaderEnv()`, `tearDownReaderEnv()`, `makeReadableWork(array $entries = ['001.jpg','002.png'], array $overrides = []): Work`, `entryBytes(string $name): string`, `writeCover(string $hash): string`.

- [ ] **Step 1: Write the test fixtures trait**

`tests/Feature/Reader/ServesReadableWork.php`:
```php
<?php

namespace Tests\Feature\Reader;

use App\Models\Mangaka;
use App\Models\Work;
use ZipArchive;

/** Builds a real on-disk zip + a Work pointing at it, and writes cover files. / 実zip＋Work行を用意。 */
trait ServesReadableWork
{
    private string $libraryPath;
    private string $dataPath;

    private function setUpReaderEnv(): void
    {
        $this->libraryPath = sys_get_temp_dir().'/wyd-rlib-'.uniqid();
        $this->dataPath = sys_get_temp_dir().'/wyd-rdata-'.uniqid();
        mkdir($this->libraryPath, 0775, true);
        mkdir($this->dataPath.'/covers', 0775, true);
        config(['scan.library_path' => $this->libraryPath, 'scan.data_path' => $this->dataPath]);
    }

    private function tearDownReaderEnv(): void
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

    /** Distinct, deterministic bytes per entry so tests can assert the RIGHT page was served. */
    private function entryBytes(string $name): string
    {
        return 'wyd-page-bytes::'.$name;
    }

    /**
     * Build a zip with the given image entries and a Work row whose stored entries match.
     *
     * @param  string[]  $entries
     * @param  array<string,mixed>  $overrides
     */
    private function makeReadableWork(array $entries = ['001.jpg', '002.png'], array $overrides = []): Work
    {
        $mangaka = Mangaka::factory()->create();
        $relative = $mangaka->slug.'/'.uniqid().'.zip';
        $abs = $this->libraryPath.'/'.$relative;
        mkdir(dirname($abs), 0775, true);

        $zip = new ZipArchive();
        $zip->open($abs, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $entry) {
            $zip->addFromString($entry, $this->entryBytes($entry));
        }
        $zip->close();

        return Work::factory()->for($mangaka)->create(array_merge([
            'relative_path' => $relative,
            'filename' => basename($relative),
            'entries' => $entries,
            'page_count' => count($entries),
        ], $overrides));
    }

    /** Write a cover file at <data>/covers/<hash>.webp; return its absolute path. */
    private function writeCover(string $hash, string $bytes = 'wyd-cover-bytes'): string
    {
        $path = $this->dataPath.'/covers/'.$hash.'.webp';
        file_put_contents($path, $bytes);

        return $path;
    }
}
```

- [ ] **Step 2: Write the failing test**

`tests/Feature/Reader/PageServingTest.php`:
```php
<?php

namespace Tests\Feature\Reader;

use App\Models\Mangaka;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageServingTest extends TestCase
{
    use RefreshDatabase;
    use ServesReadableWork;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReaderEnv();
    }

    protected function tearDown(): void
    {
        $this->tearDownReaderEnv();
        parent::tearDown();
    }

    public function test_serves_the_correct_page_bytes_and_content_type(): void
    {
        $work = $this->makeReadableWork(['001.jpg', '002.png']);

        $p1 = $this->get("/work/{$work->id}/page/1");
        $p1->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame($this->entryBytes('001.jpg'), $p1->getContent());

        $p2 = $this->get("/work/{$work->id}/page/2");
        $p2->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame($this->entryBytes('002.png'), $p2->getContent());
    }

    public function test_sets_content_hash_etag_and_returns_304_on_match(): void
    {
        $work = $this->makeReadableWork(['001.jpg']);
        $etag = '"'.$work->content_hash.'-1"';

        $this->get("/work/{$work->id}/page/1")->assertOk()->assertHeader('ETag', $etag);

        $this->withHeaders(['If-None-Match' => $etag])
            ->get("/work/{$work->id}/page/1")
            ->assertStatus(304);
    }

    public function test_out_of_range_page_is_404(): void
    {
        $work = $this->makeReadableWork(['001.jpg', '002.png']); // page_count 2

        $this->get("/work/{$work->id}/page/3")->assertNotFound();
        $this->get("/work/{$work->id}/page/0")->assertNotFound();
    }

    public function test_missing_zip_file_is_404(): void
    {
        $mangaka = Mangaka::factory()->create();
        $work = Work::factory()->for($mangaka)->create([
            'relative_path' => 'gone/missing.zip', // never built on disk
            'entries' => ['001.jpg'],
            'page_count' => 1,
        ]);

        $this->get("/work/{$work->id}/page/1")->assertNotFound();
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=PageServingTest`
Expected: FAIL — route `/work/{work}/page/{n}` is not defined (404 for the happy-path assertions / `assertOk` fails).

- [ ] **Step 4: Implement `PageController`**

`app/Http/Controllers/PageController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Archive\ArchiveException;
use App\Archive\ZipPageReader;
use App\Models\Work;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Streams a work's page bytes straight from its zip. / zipからページ画像を直接配信。 */
final class PageController extends Controller
{
    /** Entry-extension → content-type. / 拡張子→Content-Type。 */
    private const MIME = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
    ];

    public function show(Request $request, Work $work, int $n, ZipPageReader $reader): Response
    {
        $entries = $work->entries ?? [];
        if ($n < 1 || $n > count($entries)) {
            abort(404);
        }
        $entryName = $entries[$n - 1];

        // ETag from identity (content_hash) + page — immutable, so conditional GETs 304 cheaply.
        // 同一性(content_hash)+ページのETag。不変なので条件付きGETは304で安価に返す。
        $response = new Response();
        $response->setEtag($work->content_hash.'-'.$n);
        $response->headers->set('Cache-Control', 'public, max-age=31536000, immutable');
        if ($response->isNotModified($request)) {
            return $response; // 304, body skipped, zip never opened
        }

        try {
            $bytes = $reader->read(config('scan.library_path').'/'.$work->relative_path, $entryName);
        } catch (ArchiveException) {
            abort(404);
        }

        $ext = strtolower(pathinfo($entryName, PATHINFO_EXTENSION));
        $response->setContent($bytes);
        $response->headers->set('Content-Type', self::MIME[$ext] ?? 'application/octet-stream');

        return $response;
    }
}
```

- [ ] **Step 5: Register the route**

In `routes/web.php`, add the import after the existing `use App\Http\Controllers\Auth\PasswordLoginController;`:
```php
use App\Http\Controllers\PageController;
```
Append at the end of the file:
```php
Route::get('/work/{work}/page/{n}', [PageController::class, 'show'])
    ->whereNumber('n')
    ->name('work.page');
```

- [ ] **Step 6: Run test to verify it passes**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=PageServingTest`
Expected: PASS (4 tests). Then run the full suite once (`PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test`) — all green.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/PageController.php routes/web.php tests/Feature/Reader/ServesReadableWork.php tests/Feature/Reader/PageServingTest.php
git commit -m "$(cat <<'EOF'
feat: serve work pages from zip with content_hash ETag (GET /work/{work}/page/{n})

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Cover-serving endpoint — `GET /covers/{hash}.webp`

**Files:**
- Create: `app/Http/Controllers/CoverController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Reader/CoverServingTest.php`

**Interfaces:**
- Consumes: `config('scan.data_path')`; the `ServesReadableWork` trait's `setUpReaderEnv()`/`tearDownReaderEnv()`/`writeCover()` (Task 2).
- Produces: route `cover` → `CoverController::show(string $hash): \Symfony\Component\HttpFoundation\BinaryFileResponse`, constrained to `[0-9a-f]{64}`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Reader/CoverServingTest.php`:
```php
<?php

namespace Tests\Feature\Reader;

use Tests\TestCase;

class CoverServingTest extends TestCase
{
    use ServesReadableWork;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReaderEnv();
    }

    protected function tearDown(): void
    {
        $this->tearDownReaderEnv();
        parent::tearDown();
    }

    public function test_serves_an_existing_cover_as_webp(): void
    {
        $hash = str_repeat('a', 64);
        $this->writeCover($hash, 'the-cover-bytes');

        $this->get("/covers/{$hash}.webp")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/webp');
    }

    public function test_missing_cover_is_404(): void
    {
        $this->get('/covers/'.str_repeat('b', 64).'.webp')->assertNotFound();
    }

    public function test_non_hash_path_does_not_match_the_route(): void
    {
        // The [0-9a-f]{64} constraint rejects traversal / non-hex names → no route → 404.
        $this->get('/covers/not-a-valid-hash.webp')->assertNotFound();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=CoverServingTest`
Expected: FAIL — `test_serves_an_existing_cover_as_webp` gets 404 (route undefined). (The two negative tests may already pass — that's fine.)

- [ ] **Step 3: Implement `CoverController`**

`app/Http/Controllers/CoverController.php`:
```php
<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Serves a cached cover webp from the data root by content-hash. / data領域の表紙webpを配信。 */
final class CoverController extends Controller
{
    public function show(string $hash): BinaryFileResponse
    {
        $path = config('scan.data_path').'/covers/'.$hash.'.webp';
        if (! is_file($path)) {
            abort(404);
        }

        // Explicit content-type (don't sniff bytes); covers are immutable per content_hash.
        // Content-Typeは明示（内容推測しない）。表紙はcontent_hash毎に不変。
        return response()->file($path, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
```

- [ ] **Step 4: Register the route**

In `routes/web.php`, add the import after the `PageController` import:
```php
use App\Http\Controllers\CoverController;
```
Append at the end of the file:
```php
Route::get('/covers/{hash}.webp', [CoverController::class, 'show'])
    ->where('hash', '[0-9a-f]{64}')
    ->name('cover');
```

- [ ] **Step 5: Run test to verify it passes**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=CoverServingTest`
Expected: PASS (3 tests). Then full suite once — all green.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/CoverController.php routes/web.php tests/Feature/Reader/CoverServingTest.php
git commit -m "$(cat <<'EOF'
feat: serve cached covers by hash (GET /covers/{hash}.webp)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Reading-progress endpoint — `POST /work/{work}/progress`

**Files:**
- Create: `app/Http/Controllers/ReadingProgressController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Reader/ReadingProgressTest.php`

**Interfaces:**
- Consumes: `App\Models\Work` (`page_count`), `App\Models\ReadingProgress`.
- Produces: route `work.progress` → `ReadingProgressController::update(Request, Work): \Illuminate\Http\JsonResponse`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Reader/ReadingProgressTest.php`:
```php
<?php

namespace Tests\Feature\Reader;

use App\Models\Mangaka;
use App\Models\ReadingProgress;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReadingProgressTest extends TestCase
{
    use RefreshDatabase;

    private function work(int $pageCount = 10): Work
    {
        return Work::factory()->for(Mangaka::factory())->create(['page_count' => $pageCount]);
    }

    public function test_creates_progress_row_with_timestamps(): void
    {
        $work = $this->work(10);

        $this->postJson("/work/{$work->id}/progress", ['current_page' => 3])
            ->assertOk()
            ->assertJson(['current_page' => 3, 'is_completed' => false]);

        $progress = ReadingProgress::where('work_id', $work->id)->firstOrFail();
        $this->assertSame(3, $progress->current_page);
        $this->assertFalse($progress->is_completed);
        $this->assertNotNull($progress->started_at);
        $this->assertNotNull($progress->last_read_at);
        $this->assertNull($progress->completed_at);
    }

    public function test_reaching_last_page_marks_completed(): void
    {
        $work = $this->work(5);

        $this->postJson("/work/{$work->id}/progress", ['current_page' => 5])
            ->assertOk()
            ->assertJson(['current_page' => 5, 'is_completed' => true]);

        $progress = ReadingProgress::where('work_id', $work->id)->firstOrFail();
        $this->assertTrue($progress->is_completed);
        $this->assertNotNull($progress->completed_at);
    }

    public function test_updates_existing_row_and_preserves_started_at(): void
    {
        $work = $this->work(10);

        $this->postJson("/work/{$work->id}/progress", ['current_page' => 2])->assertOk();
        $first = ReadingProgress::where('work_id', $work->id)->firstOrFail();
        $startedAt = $first->started_at;

        $this->postJson("/work/{$work->id}/progress", ['current_page' => 6])->assertOk();

        $this->assertSame(1, ReadingProgress::where('work_id', $work->id)->count()); // upsert, not duplicate
        $second = ReadingProgress::where('work_id', $work->id)->firstOrFail();
        $this->assertSame(6, $second->current_page);
        $this->assertEquals($startedAt, $second->started_at); // started_at unchanged
    }

    public function test_rejects_out_of_range_page(): void
    {
        $work = $this->work(5);

        $this->postJson("/work/{$work->id}/progress", ['current_page' => 6])->assertStatus(422);
        $this->postJson("/work/{$work->id}/progress", ['current_page' => 0])->assertStatus(422);
        $this->postJson("/work/{$work->id}/progress", [])->assertStatus(422);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=ReadingProgressTest`
Expected: FAIL — route `/work/{work}/progress` is not defined (assertions get 404, not 200/422).

- [ ] **Step 3: Implement `ReadingProgressController`**

`app/Http/Controllers/ReadingProgressController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Models\ReadingProgress;
use App\Models\Work;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Upserts the single per-work reading-progress row. / 作品ごとの読書進捗を更新（1行）。 */
final class ReadingProgressController extends Controller
{
    public function update(Request $request, Work $work): JsonResponse
    {
        $validated = $request->validate([
            'current_page' => ['required', 'integer', 'min:1', 'max:'.$work->page_count],
        ]);
        $page = (int) $validated['current_page'];

        $progress = ReadingProgress::firstOrNew(['work_id' => $work->id]);
        $progress->current_page = $page;
        $progress->started_at ??= now();           // set once, on first read / 初回のみ
        $progress->last_read_at = now();            // every save / 毎回
        $progress->is_completed = $page >= $work->page_count;
        $progress->completed_at = $progress->is_completed
            ? ($progress->completed_at ?? now())    // preserve first completion / 初完了を保持
            : null;
        $progress->save();

        return response()->json([
            'current_page' => $progress->current_page,
            'is_completed' => $progress->is_completed,
        ]);
    }
}
```

- [ ] **Step 4: Register the route**

In `routes/web.php`, add the import after the `CoverController` import:
```php
use App\Http\Controllers\ReadingProgressController;
```
Append at the end of the file:
```php
Route::post('/work/{work}/progress', [ReadingProgressController::class, 'update'])
    ->name('work.progress');
```

- [ ] **Step 5: Run test to verify it passes**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=ReadingProgressTest`
Expected: PASS (4 tests).

- [ ] **Step 6: Run the full suite (no regressions)**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test`
Expected: PASS — all suites green (archive, parsing, scanning, series, reader, auth).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/ReadingProgressController.php routes/web.php tests/Feature/Reader/ReadingProgressTest.php
git commit -m "$(cat <<'EOF'
feat: save reading progress (POST /work/{work}/progress)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Self-Review

**Spec §9 coverage (backend portion):**
- "`GET /work/{work}/page/{n}` — opens the zip and streams the n-th image entry's bytes directly, correct content-type, long-lived ETag (`content_hash` + page), no extraction to disk, uses the stored `entries` list so it never re-lists the archive" → Task 2 `PageController` (entry from `entries[n-1]`, `ZipPageReader::read` via `getFromName`, MIME map, `ETag "<hash>-<n>"`, immutable cache, 304); `PageServingTest`.
- "`GET /covers/{hash}.webp` — served statically from `/data`" → Task 3 `CoverController` (file from `data_path/covers`, hash-constrained route, explicit webp content-type); `CoverServingTest`.
- "Debounced `POST /work/{work}/progress` to save `current_page`" (the endpoint half) → Task 4 `ReadingProgressController`; `ReadingProgressTest`.
- "Cover generation uses Intervention Image … in the scan worker" → already done in Plan 3 (out of scope here).
- The **JS (Alpine.js)** half of §9 (page swap, keyboard/click nav, preload, RTL toggle, debounced *client*) → deferred to the frontend plan (Scope Decision 1), which also brings the work-detail launch point and §10 browse.

**Placeholder scan:** none — every step has full code and an exact command with expected output.

**Type consistency:** `ZipPageReader::read(string,string): string` is identical across Task 1, its consumer in Task 2, and the reference block. `makeReadableWork`/`entryBytes`/`writeCover`/`setUpReaderEnv`/`tearDownReaderEnv` signatures match between the trait (Task 2) and its callers (Tasks 2 & 3). Route names (`work.page`, `cover`, `work.progress`) and the `[0-9a-f]{64}` / `whereNumber('n')` constraints are consistent. Controller signatures match their routes. `ReadingProgress` field names + casts (`current_page`, `is_completed`, `started_at`, `last_read_at`, `completed_at`) match the model.

**Out of scope (intentional, per Scope Decisions):** Alpine reader JS, work-detail & browse views (§10), HTTP range requests, chunked streaming.
