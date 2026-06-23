<?php

use App\Models\ReadingProgress;
use App\Models\Work;
use App\Scanning\LibraryScanner;
use Tests\Feature\Scanning\BuildsLibraryFixtures;

uses(BuildsLibraryFixtures::class);

beforeEach(fn () => $this->bootLibrary());
afterEach(fn () => $this->cleanLibrary());

test('removed file is flagged missing and keeps progress', function (): void {
    $path = $this->makeDoujin('Circle', 'Title');
    app(LibraryScanner::class)->scan();
    $work = Work::firstOrFail();
    ReadingProgress::create(['work_id' => $work->id, 'current_page' => 3]);

    $this->travel(1)->days();
    unlink($path); // file disappears
    $stats = app(LibraryScanner::class)->scan();

    $this->assertSame(1, $stats['missing']);
    $work->refresh();
    $this->assertTrue($work->is_missing);
    $this->assertSame(3, $work->readingProgress->current_page); // never deleted
});

test('reappeared file is unflagged', function (): void {
    $this->makeDoujin('Circle', 'Title', ['001.jpg']);
    app(LibraryScanner::class)->scan();
    $work = Work::firstOrFail();
    $work->update(['is_missing' => true]); // simulate previously missing

    $stats = app(LibraryScanner::class)->scan();

    $work->refresh();
    $this->assertFalse($work->is_missing);
    $this->assertSame(0, $stats['missing']);
});

test('corrupt zip increments failed and scan continues', function (): void {
    $this->makeDoujin('Circle', 'Good', ['001.jpg']);
    // A .zip that is not a valid archive.
    file_put_contents($this->libraryPath.'/Circle/Bad.zip', 'not a zip');

    $stats = app(LibraryScanner::class)->scan();

    $this->assertSame(1, $stats['added']);  // the good one
    $this->assertSame(1, $stats['failed']); // the bad one
    $this->assertSame(1, Work::count());
});

test('content replaced at same path adds new work and flags old missing', function (): void {
    $path = $this->makeDoujin('Circle', 'Title', ['001.jpg']);
    app(LibraryScanner::class)->scan();
    $old = Work::firstOrFail();
    $oldHash = $old->content_hash;

    $this->travel(1)->days();
    // Replace the zip's CONTENT at the same path (different entry list → different content_hash).
    unlink($path);
    $this->makeDoujin('Circle', 'Title', ['001.jpg', '002.jpg', '003.jpg']);

    $stats = app(LibraryScanner::class)->scan();

    $this->assertSame(1, $stats['added']);   // new content = new work
    $this->assertSame(1, $stats['missing']); // old content gone → missing
    $this->assertSame(2, Work::count());
    $old->refresh();
    $this->assertTrue($old->is_missing);                          // old flagged missing
    $this->assertSame($oldHash, $old->content_hash);              // its identity unchanged
    $new = Work::where('is_missing', false)->firstOrFail();
    $this->assertNotSame($oldHash, $new->content_hash);           // genuinely new identity
    $this->assertSame($old->relative_path, $new->relative_path);  // share the path (benign; old is hidden)
});
