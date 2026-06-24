<?php

use App\Models\Mangaka;
use App\Models\Work;
use Illuminate\Support\Str;
use Tests\Feature\Scanning\BuildsLibraryFixtures;

uses(BuildsLibraryFixtures::class);

beforeEach(fn () => $this->bootLibrary());
afterEach(fn () => $this->cleanLibrary());

// Mangaka resolved by name, not re-created. / 名前で引き当て、重複作成しない。
test('duplicate mangaka name gets a suffixed slug', function (): void {
    $base = Str::slug('Circle');
    Mangaka::create(['name' => 'Circle', 'slug' => $base]);

    $this->makeDoujin('Circle', 'Title');
    $this->runScan();

    $this->assertSame(1, Mangaka::where('name', 'Circle')->count());
});

// Slug collision on a different name with the same slug base → "$base-2".
// / 同じスラッグ基底の別名 → 増番。
test('slug collision on different name with same slug base creates suffixed slug', function (): void {
    Mangaka::create(['name' => 'Circle', 'slug' => 'circle']);

    $dir = $this->libraryPath.'/Çircle'; // also slugifies to "circle"
    mkdir($dir, 0775, true);
    $zip = new ZipArchive();
    $zip->open($dir.'/Title.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('001.jpg', $this->pngBytes());
    $zip->close();

    $this->runScan();

    $this->assertTrue(Mangaka::where('slug', 'circle-2')->exists());
});

// Empty zip (no image entries) → cover_path stays null, no GenerateCover dispatched.
// / 画像なしzip → cover_pathはnull。
test('empty zip scan yields null cover_path and zero page count', function (): void {
    $dir = $this->libraryPath.'/Circle';
    mkdir($dir, 0775, true);
    $zip = new ZipArchive();
    $zip->open($dir.'/Title.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('readme.txt', 'no images here');
    $zip->close();

    $scan = $this->runScan();

    $this->assertSame(1, $scan->stats['added']);
    $work = Work::firstOrFail();
    $this->assertNull($work->cover_path);
    $this->assertSame(0, $work->page_count);
    $this->assertSame([], $work->entries);
});

// Empty library: nothing to fan out, scan still finalises cleanly. / 空ライブラリでも完了。
test('empty library finalises with zero stats', function (): void {
    $scan = $this->runScan();

    $this->assertSame('completed', $scan->status);
    $this->assertSame(0, $scan->stats['added']);
    $this->assertSame(0, $scan->stats['missing']);
    $this->assertSame(0, Work::count());
});
