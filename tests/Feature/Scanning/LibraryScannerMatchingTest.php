<?php

use App\Models\ReadingProgress;
use App\Models\Work;
use App\Scanning\LibraryScanner;
use Tests\Feature\Scanning\BuildsLibraryFixtures;

uses(BuildsLibraryFixtures::class);

beforeEach(fn () => $this->bootLibrary());
afterEach(fn () => $this->cleanLibrary());

test('fresh scan creates works with parsed metadata and cover', function (): void {
    $this->makeDoujin('Z.A.P.', '(C89) [Z.A.P. (ズッキーニ)] 四畳半物語 (オリジナル) [DL版]');

    $stats = app(LibraryScanner::class)->scan();

    $this->assertSame(1, $stats['added']);
    $work = Work::firstOrFail();
    $this->assertSame('四畳半物語', $work->title);
    // Metadata now lives in tags. / メタデータはタグに。
    $this->assertEqualsCanonicalizing([
        ['event', 'C89'], ['circle', 'Z.A.P.'], ['author', 'ズッキーニ'],
        ['parody', 'オリジナル'], ['flag', 'DL版'],
    ], $work->tags()->get()->map(fn ($t) => [$t->type, $t->value])->all());
    $this->assertSame(2, $work->page_count);
    $this->assertSame(['001.jpg', '002.jpg'], $work->entries);
    $this->assertSame('Z.A.P.', $work->mangaka->name);
    $this->assertNotEmpty($work->mangaka->slug);
    $this->assertNotNull($work->cover_path);
    $this->assertFileExists($this->dataPath.'/'.$work->cover_path);
});

test('japanese mangaka folder gets nonempty slug', function (): void {
    $this->makeDoujin('ズッキーニ', 'タイトル');

    app(LibraryScanner::class)->scan();

    $work = Work::firstOrFail();
    $this->assertSame('ズッキーニ', $work->mangaka->name);
    $this->assertNotEmpty($work->mangaka->slug);
    $this->assertStringStartsWith('mangaka-', $work->mangaka->slug);
});

test('rescan unchanged file is skipped', function (): void {
    $this->makeDoujin('Circle', 'Title');
    $first = app(LibraryScanner::class)->scan();
    $this->assertSame(1, $first['added']);

    $second = app(LibraryScanner::class)->scan();
    $this->assertSame(0, $second['added']);
    $this->assertSame(0, $second['updated']);
    $this->assertSame(0, $second['moved']);
    $this->assertSame(1, Work::count());
});

test('moved file keeps reading progress', function (): void {
    $path = $this->makeDoujin('OldCircle', 'Title');
    app(LibraryScanner::class)->scan();
    $work = Work::firstOrFail();
    ReadingProgress::create(['work_id' => $work->id, 'current_page' => 7]);

    // Move the zip to a different mangaka folder (same bytes → same content_hash).
    $newDir = $this->libraryPath.'/NewCircle';
    mkdir($newDir, 0775, true);
    rename($path, $newDir.'/Title.zip');

    $stats = app(LibraryScanner::class)->scan();

    $this->assertSame(1, $stats['moved']);
    $this->assertSame(1, Work::count());
    $work->refresh();
    $this->assertSame('NewCircle/Title.zip', $work->relative_path);
    $this->assertSame('NewCircle', $work->mangaka->name);
    $this->assertSame(7, $work->readingProgress->current_page); // progress preserved
});
