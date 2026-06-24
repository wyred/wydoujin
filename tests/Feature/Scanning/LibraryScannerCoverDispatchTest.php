<?php

use App\Jobs\GenerateCover;
use App\Models\Work;
use App\Scanning\LibraryScanner;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Scanning\BuildsLibraryFixtures;

uses(BuildsLibraryFixtures::class);

beforeEach(fn () => $this->bootLibrary());
afterEach(fn () => $this->cleanLibrary());

test('a newly added work queues a cover job instead of generating inline', function (): void {
    Queue::fake();
    $this->makeDoujin('Circle', 'Title', ['001.jpg']);

    $stats = app(LibraryScanner::class)->scan();

    $this->assertSame(1, $stats['added']);
    $work = Work::firstOrFail();
    $this->assertNull($work->cover_path); // not generated inline; left for the worker

    Queue::assertPushed(GenerateCover::class, 1);
    Queue::assertPushed(GenerateCover::class, fn (GenerateCover $job) => $job->workId === $work->id);
});

test('a zip with no images queues no cover job', function (): void {
    Queue::fake();
    $dir = $this->libraryPath.'/Circle';
    mkdir($dir, 0775, true);
    $zip = new ZipArchive;
    $zip->open($dir.'/NoImages.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('readme.txt', 'no images');
    $zip->close();

    app(LibraryScanner::class)->scan();

    Queue::assertNotPushed(GenerateCover::class);
});

test('rescan of an unchanged work queues no new cover job', function (): void {
    $this->makeDoujin('Circle', 'Title', ['001.jpg']);
    app(LibraryScanner::class)->scan(); // first pass generates the cover (sync)

    Queue::fake();
    app(LibraryScanner::class)->scan(); // second pass: fast-skip, no work

    Queue::assertNotPushed(GenerateCover::class);
});
