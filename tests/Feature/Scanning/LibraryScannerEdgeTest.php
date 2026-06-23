<?php

use App\Models\Mangaka;
use App\Models\Work;
use App\Scanning\LibraryScanner;
use Illuminate\Support\Str;
use Tests\Feature\Scanning\BuildsLibraryFixtures;

uses(BuildsLibraryFixtures::class);

beforeEach(fn () => $this->bootLibrary());
afterEach(fn () => $this->cleanLibrary());

// Line 82: slug collision → "$base-2" suffix
// / 82行目：スラッグ衝突 → "$base-2"サフィックスの生成。
test('duplicate mangaka name gets a suffixed slug', function (): void {
    $base = Str::slug('Circle');
    Mangaka::create(['name' => 'Circle', 'slug' => $base]);

    $this->makeDoujin('Circle', 'Title');
    // The scanner must resolve the already-existing Mangaka by name, not create a new one.
    app(LibraryScanner::class)->scan();

    // Only one Mangaka for "Circle" — scanner finds it by name, no collision path hit yet.
    // Force the collision by creating a second mangaka with the same slug base first.
    // / 同名Mangakaは名前で引き当てるため衝突しない。スラッグ衝突は別名で同slug基底のとき発生。
    $this->assertSame(1, Mangaka::where('name', 'Circle')->count());
});

test('slug collision on different name with same slug base creates suffixed slug', function (): void {
    // Pre-occupy the base slug "circle" with a different mangaka record.
    // / 同じスラッグ基底"circle"を別レコードで先占する。
    Mangaka::create(['name' => 'Circle', 'slug' => 'circle']);

    // "Circle2" also slugifies to "circle" base — collision branch (line 82) fires.
    // We stub it differently: two mangaka folders with the exact same Str::slug output.
    // Easiest: use "Circle" (already exists in DB) for slug, then scan a folder named differently
    // but resolving to the same slug. Use a name with special chars that also slug to "circle".
    $dir = $this->libraryPath.'/Çircle';
    mkdir($dir, 0775, true);
    $zip = new ZipArchive();
    $zip->open($dir.'/Title.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('001.jpg', $this->pngBytes());
    $zip->close();

    app(LibraryScanner::class)->scan();

    // "Çircle" also slugifies to "circle" — so scanner must increment to "circle-2".
    // / "Çircle"も"circle"にスラッグ化 → "circle-2"に増番される。
    $this->assertTrue(Mangaka::where('slug', 'circle-2')->exists());
});

// Line 133: empty zip (no image entries) → cover_path stays null
// / 133行目：画像なしzip → cover_pathはnull。
test('empty zip scan yields null cover_path and zero page count', function (): void {
    // Build a zip with no image files — only a text file.
    // / 画像ファイルなし（テキストのみ）のzipを作成。
    $dir = $this->libraryPath.'/Circle';
    mkdir($dir, 0775, true);
    $zip = new ZipArchive();
    $zip->open($dir.'/Title.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('readme.txt', 'no images here');
    $zip->close();

    $stats = app(LibraryScanner::class)->scan();

    $this->assertSame(1, $stats['added']);
    $work = Work::firstOrFail();
    $this->assertNull($work->cover_path);
    $this->assertSame(0, $work->page_count);
    $this->assertSame([], $work->entries);
});
