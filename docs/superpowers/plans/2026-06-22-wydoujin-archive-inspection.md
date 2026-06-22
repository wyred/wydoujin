# wydoujin — Archive Inspection & Cover Generation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the pure zip/image units the scanner depends on — read a `.zip`'s central directory into a `content_hash` + natural-sorted image entry list (spec §5/§7), and render a resized webp cover from one entry (Intervention v4).

**Architecture:** Two stateless `App\Archive` units with injected settings (no DB, no library walk). `ArchiveInspector` reads the zip central directory via PHP's `ZipArchive` (no decompression) → an `ArchiveInspection` value object (`contentHash`, `imageEntries`, `pageCount`). `CoverGenerator` reads one entry's bytes and produces `<coversDir>/<hash>.webp`. Both throw `ArchiveException` on bad input; the scan job (Plan 4) will catch it. This is "Layer A" of the scanner; Plan 4 (orchestration) injects these with config-driven settings.

**Tech Stack:** PHP 8.3+ (readonly promoted properties), ext-zip (`ZipArchive`), ext-gd, intervention/image v4, PHPUnit.

## Global Constraints

- **Framework/PHP:** Laravel 13, PHP 8.3+. Composer platform pinned to `8.3.0`; local dev runs 8.5.
- **Broken local toolchain:** `php`/`composer` on PATH point at a broken php@7.4. Prefix EVERY php command with `PATH="/opt/homebrew/opt/php/bin:$PATH"` (working PHP 8.5.4). Shell env does not persist between commands — repeat the prefix.
- **Commit trailer:** every commit message ends with a blank line then exactly:
  `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`
- **PHP style:** single quotes unless interpolation; native typed (readonly promoted) properties over `@var`; keep `@param`/`@var` only for array-element types PHP can't express (e.g. `string[]`); comments in BOTH English and Japanese in the same docblock, short.
- **Purity:** these units touch NO database and NO Eloquent. Tests extend `PHPUnit\Framework\TestCase` (no Laravel app boot). They MAY use the filesystem (temp zips, a temp covers dir) but no DB; clean up temp files in `tearDown()`.
- **Extensions:** `ext-zip` and `ext-gd` are required (present locally, in the Docker image, and in CI). intervention/image v4 is installed.
- **`content_hash` (locked):** `sha256` hex (64 chars — fits `works.content_hash`) over the **name-sorted** list of `name + "\0" + size + "\n"` for the **file** entries (directories excluded). It is order- and path-independent (survives moves, per §5). It hashes entry *metadata* (names+sizes), NOT file contents — cheap, no decompression; two distinct zips with identical entry names+sizes would collide. This is the spec's identity heuristic; document it in the code.
- **Natural sort (locked):** `strnatcasecmp` over full entry paths → `1,2,…,10` (not `1,10,2`) and correct nested-folder order (`ch1/001` < `ch1/010` < `ch2/001`).
- **Image extensions (locked):** `jpg, jpeg, png, gif, webp, avif` (case-insensitive), baked as `ArchiveInspector::DEFAULT_IMAGE_EXTENSIONS` (overridable via constructor). Skip directory entries and non-image junk (`Thumbs.db`, `.DS_Store`, …).
- **Cover (locked):** Intervention v4 GD driver → `scaleDown(width:)` (default **400**, never upscales) → `toWebp(quality)` (default **80**) → `<coversDir>/<hash>.webp`; `generate()` returns the relative path `covers/<hash>.webp` (what `works.cover_path` stores). `coversDir`, width, quality are injected.
- **Out of scope (Plan 4 — orchestration):** the `/library` walk, DB writes, `config/scan.php`, container bindings, the queued job, the `scans` lifecycle, the artisan command + scheduled scan. Plan 4 injects these units with config-driven settings.
- **Workflow:** TDD (failing test first), DRY, YAGNI, bite-sized commits. Fixture zips built in-test via `ZipArchive::addFromString`; the cover test builds a real image with GD.

## File Structure

Created in this plan:

- `app/Archive/ArchiveException.php` — `RuntimeException` subtype for unopenable/unreadable/undecodable archives.
- `app/Archive/ArchiveInspection.php` — immutable result: `contentHash`, `imageEntries`, `pageCount`.
- `app/Archive/ArchiveInspector.php` — `inspect(string $zipPath): ArchiveInspection` (central directory → hash + filtered/natural-sorted images + count).
- `app/Archive/CoverGenerator.php` — `generate(string $zipPath, string $entryName, string $contentHash): string` (one entry → resized webp).
- `tests/Unit/Archive/ArchiveInspectorTest.php`, `tests/Unit/Archive/CoverGeneratorTest.php`.

---

## Task 1: `ArchiveInspector` (+ `ArchiveInspection` DTO + `ArchiveException`)

**Files:**
- Create: `app/Archive/ArchiveException.php`, `app/Archive/ArchiveInspection.php`, `app/Archive/ArchiveInspector.php`
- Test: `tests/Unit/Archive/ArchiveInspectorTest.php`

**Interfaces:**
- Consumes: PHP `ZipArchive` (ext-zip).
- Produces:
  - `App\Archive\ArchiveException extends \RuntimeException`.
  - `App\Archive\ArchiveInspection` — `final` class, readonly props `string $contentHash; array $imageEntries; int $pageCount`.
  - `App\Archive\ArchiveInspector` — `__construct(array $imageExtensions = self::DEFAULT_IMAGE_EXTENSIONS)`; `public const DEFAULT_IMAGE_EXTENSIONS = ['jpg','jpeg','png','gif','webp','avif']`; `inspect(string $zipPath): ArchiveInspection` (throws `ArchiveException` if the zip can't be opened). Plan 4 injects an `ArchiveInspector` and reads `ArchiveInspection`.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Archive/ArchiveInspectorTest.php`:
```php
<?php

namespace Tests\Unit\Archive;

use App\Archive\ArchiveException;
use App\Archive\ArchiveInspector;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class ArchiveInspectorTest extends TestCase
{
    /** @var string[] temp files to remove */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        parent::tearDown();
    }

    /** @param array<string,string> $entries name => contents */
    private function makeZip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'wyd').'.zip';
        $this->tempFiles[] = $path;
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return $path;
    }

    public function test_filters_images_and_natural_sorts_including_nested(): void
    {
        $zip = $this->makeZip([
            '10.jpg' => 'x', '1.jpg' => 'x', '2.jpg' => 'x',
            'cover.png' => 'x', 'ch1/005.webp' => 'x',
            'Thumbs.db' => 'x', 'notes.txt' => 'x',
        ]);

        $r = (new ArchiveInspector())->inspect($zip);

        $this->assertSame(
            ['1.jpg', '2.jpg', '10.jpg', 'ch1/005.webp', 'cover.png'],
            $r->imageEntries,
        );
        $this->assertSame(5, $r->pageCount);
    }

    public function test_content_hash_is_order_independent_but_size_sensitive(): void
    {
        $a = $this->makeZip(['a.jpg' => 'A', 'b.jpg' => 'BB']);
        $b = $this->makeZip(['b.jpg' => 'BB', 'a.jpg' => 'A']);        // different insertion order
        $c = $this->makeZip(['a.jpg' => 'DIFFERENT', 'b.jpg' => 'BB']); // different size

        $inspector = new ArchiveInspector();
        $hashA = $inspector->inspect($a)->contentHash;
        $hashB = $inspector->inspect($b)->contentHash;
        $hashC = $inspector->inspect($c)->contentHash;

        $this->assertSame($hashA, $hashB);     // order-independent
        $this->assertNotSame($hashA, $hashC);  // size-sensitive
        $this->assertSame(64, strlen($hashA)); // sha256 hex
    }

    public function test_zip_with_no_images_yields_empty_entries(): void
    {
        $zip = $this->makeZip(['readme.txt' => 'hi', 'Thumbs.db' => 'x']);

        $r = (new ArchiveInspector())->inspect($zip);

        $this->assertSame([], $r->imageEntries);
        $this->assertSame(0, $r->pageCount);
        $this->assertSame(64, strlen($r->contentHash));
    }

    public function test_throws_on_unopenable_zip(): void
    {
        $bad = tempnam(sys_get_temp_dir(), 'wyd').'.zip';
        $this->tempFiles[] = $bad;
        file_put_contents($bad, 'this is not a zip');

        $this->expectException(ArchiveException::class);
        (new ArchiveInspector())->inspect($bad);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=ArchiveInspectorTest`
Expected: FAIL (classes `App\Archive\*` not found).

- [ ] **Step 3: Write `ArchiveException`**

`app/Archive/ArchiveException.php`:
```php
<?php

namespace App\Archive;

use RuntimeException;

/** Thrown when an archive can't be opened/read/decoded. / アーカイブの読み取り失敗時に送出。 */
final class ArchiveException extends RuntimeException
{
}
```

- [ ] **Step 4: Write the `ArchiveInspection` DTO**

`app/Archive/ArchiveInspection.php`:
```php
<?php

namespace App\Archive;

/** Result of reading a zip's central directory. / zip中央ディレクトリ読み取り結果。 */
final class ArchiveInspection
{
    /** @param string[] $imageEntries ordered, natural-sorted in-zip image paths */
    public function __construct(
        public readonly string $contentHash,
        public readonly array $imageEntries,
        public readonly int $pageCount,
    ) {
    }
}
```

- [ ] **Step 5: Write the `ArchiveInspector`**

`app/Archive/ArchiveInspector.php`:
```php
<?php

namespace App\Archive;

use ZipArchive;

/**
 * Reads a zip's central directory (no decompression): content_hash + image entries.
 * zipの中央ディレクトリのみ読む（解凍なし）：content_hash と画像エントリ。
 */
final class ArchiveInspector
{
    public const DEFAULT_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];

    /** @var string[] lowercase image extensions */
    private array $imageExtensions;

    /** @param string[] $imageExtensions */
    public function __construct(array $imageExtensions = self::DEFAULT_IMAGE_EXTENSIONS)
    {
        $this->imageExtensions = array_map('strtolower', $imageExtensions);
    }

    /** @throws ArchiveException if the zip cannot be opened */
    public function inspect(string $zipPath): ArchiveInspection
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new ArchiveException("Cannot open zip: {$zipPath}");
        }

        try {
            $files = [];   // name => size (file entries, for the hash)
            $images = [];  // image entry paths
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false || $this->isDirectory($stat['name'])) {
                    continue;
                }
                $files[$stat['name']] = $stat['size'];
                if ($this->isImage($stat['name'])) {
                    $images[] = $stat['name'];
                }
            }
        } finally {
            $zip->close();
        }

        usort($images, 'strnatcasecmp'); // 1,2,…,10 + nested folders / 自然順

        return new ArchiveInspection(
            contentHash: $this->hashEntries($files),
            imageEntries: $images,
            pageCount: count($images),
        );
    }

    private function isDirectory(string $name): bool
    {
        return str_ends_with($name, '/');
    }

    private function isImage(string $name): bool
    {
        return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), $this->imageExtensions, true);
    }

    /**
     * sha256 over the name-sorted "name\0size" list — order/path-independent identity.
     * 名前順の "name\0size" 列を sha256。順序・パス非依存の同一性（内容ではなくメタdata）。
     *
     * @param array<string,int> $files
     */
    private function hashEntries(array $files): string
    {
        ksort($files, SORT_STRING);
        $canonical = '';
        foreach ($files as $name => $size) {
            $canonical .= $name."\0".$size."\n";
        }

        return hash('sha256', $canonical);
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=ArchiveInspectorTest`
Expected: PASS (4 tests). Then run the full suite once (`PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test`) — all green, pristine.

- [ ] **Step 7: Commit**

```bash
git add app/Archive/ArchiveException.php app/Archive/ArchiveInspection.php app/Archive/ArchiveInspector.php tests/Unit/Archive/ArchiveInspectorTest.php
git commit -m "$(cat <<'EOF'
feat: add ArchiveInspector (content_hash + image entries from zip central directory)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: `CoverGenerator`

**Files:**
- Create: `app/Archive/CoverGenerator.php`
- Test: `tests/Unit/Archive/CoverGeneratorTest.php`

**Interfaces:**
- Consumes: PHP `ZipArchive`, `Intervention\Image\ImageManager` (v4), `App\Archive\ArchiveException`.
- Produces: `App\Archive\CoverGenerator` — `__construct(string $coversDir, int $width = 400, int $quality = 80)`; `generate(string $zipPath, string $entryName, string $contentHash): string` returns the relative path `covers/<hash>.webp` and writes `<coversDir>/<hash>.webp`. Throws `ArchiveException` if the entry can't be read or the image can't be decoded. Plan 4 injects a `CoverGenerator` (with `coversDir = <data>/covers`) and stores the returned path in `works.cover_path`.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Archive/CoverGeneratorTest.php`:
```php
<?php

namespace Tests\Unit\Archive;

use App\Archive\ArchiveException;
use App\Archive\CoverGenerator;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class CoverGeneratorTest extends TestCase
{
    /** @var string[] */
    private array $tempFiles = [];
    private string $coversDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->coversDir = sys_get_temp_dir().'/wyd-covers-'.uniqid();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        if (is_dir($this->coversDir)) {
            array_map('unlink', glob($this->coversDir.'/*') ?: []);
            rmdir($this->coversDir);
        }
        parent::tearDown();
    }

    /** @param array<string,string> $entries */
    private function makeZip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'wyd').'.zip';
        $this->tempFiles[] = $path;
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return $path;
    }

    private function pngBytes(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 100, 150, 200));
        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        imagedestroy($img);

        return $png;
    }

    public function test_generates_resized_webp_cover(): void
    {
        $zip = $this->makeZip(['001.png' => $this->pngBytes(800, 600)]);

        $coverPath = (new CoverGenerator($this->coversDir, 400, 80))
            ->generate($zip, '001.png', 'deadbeef');

        $this->assertSame('covers/deadbeef.webp', $coverPath);
        $file = $this->coversDir.'/deadbeef.webp';
        $this->assertFileExists($file);

        $info = getimagesize($file);
        $this->assertNotFalse($info);
        $this->assertSame(IMAGETYPE_WEBP, $info[2]);
        $this->assertLessThanOrEqual(400, $info[0]); // scaled down from 800
    }

    public function test_throws_when_entry_missing(): void
    {
        $zip = $this->makeZip(['001.png' => $this->pngBytes(100, 100)]);

        $this->expectException(ArchiveException::class);
        (new CoverGenerator($this->coversDir))->generate($zip, 'nope.png', 'h');
    }

    public function test_throws_on_undecodable_image(): void
    {
        $zip = $this->makeZip(['bad.png' => 'not a real image']);

        $this->expectException(ArchiveException::class);
        (new CoverGenerator($this->coversDir))->generate($zip, 'bad.png', 'h');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=CoverGeneratorTest`
Expected: FAIL (class `App\Archive\CoverGenerator` not found).

- [ ] **Step 3: Write the `CoverGenerator`**

`app/Archive/CoverGenerator.php`:
```php
<?php

namespace App\Archive;

use Intervention\Image\ImageManager;
use Throwable;
use ZipArchive;

/**
 * Renders a resized webp cover from one zip entry. / zip内1エントリから縮小webp表紙を生成。
 */
final class CoverGenerator
{
    public function __construct(
        private readonly string $coversDir,
        private readonly int $width = 400,
        private readonly int $quality = 80,
    ) {
    }

    /**
     * @return string the cover path relative to the data root, e.g. covers/<hash>.webp
     *
     * @throws ArchiveException if the entry can't be read or the image can't be decoded
     */
    public function generate(string $zipPath, string $entryName, string $contentHash): string
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

        if (! is_dir($this->coversDir)) {
            mkdir($this->coversDir, 0775, true);
        }

        try {
            $encoded = ImageManager::gd()
                ->read($bytes)
                ->scaleDown(width: $this->width) // never upscales / 拡大はしない
                ->toWebp($this->quality);
        } catch (Throwable $e) {
            throw new ArchiveException("Cannot decode cover image for {$entryName}: {$e->getMessage()}", 0, $e);
        }

        $relative = 'covers/'.$contentHash.'.webp';
        $encoded->save($this->coversDir.'/'.$contentHash.'.webp');

        return $relative;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=CoverGeneratorTest`
Expected: PASS (3 tests). Then run the full suite (`PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test`) — all green, pristine.

- [ ] **Step 5: Commit**

```bash
git add app/Archive/CoverGenerator.php tests/Unit/Archive/CoverGeneratorTest.php
git commit -m "$(cat <<'EOF'
feat: add CoverGenerator (resized webp cover from a zip entry via Intervention v4)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Self-Review Notes

- **Spec §5/§7 coverage (Layer A):** `content_hash` as a path-independent hash of the zip entry list (names+sizes), read from the central directory without decompression → `ArchiveInspector::hashEntries` (Task 1). Image-entry filtering by extension, skipping directories/junk → `isImage`/`isDirectory` (Task 1). Natural sort incl. nested folders → `usort(..., 'strnatcasecmp')` (Task 1). `page_count` + ordered `entries` → `ArchiveInspection` (Task 1). Resized webp cover at `<data>/covers/<hash>.webp` via Intervention → `CoverGenerator` (Task 2). The library walk, incremental skip, hash matching, missing detection, parser integration, `scans` lifecycle, command + schedule are explicitly Plan 4.
- **Purity:** no DB/Eloquent; both test files extend `PHPUnit\Framework\TestCase`; filesystem use is temp-only with `tearDown` cleanup.
- **Type consistency:** `ArchiveInspection` field names (`contentHash`, `imageEntries`, `pageCount`) and the `CoverGenerator::generate(zipPath, entryName, contentHash)` signature are the exact interface Plan 4 will consume; `ArchiveException` is the shared error type both units throw.
- **No placeholders:** every step has complete code and an exact run command. Fixture zips are built in-test via `ZipArchive::addFromString`; the cover test uses GD to make a real PNG so Intervention actually decodes/encodes.
- **Intervention v4 API used:** `ImageManager::gd()->read($bytes)->scaleDown(width:)->toWebp($quality)->save($path)` — the v4 fluent API (not v3's `Image::make`).
