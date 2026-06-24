<?php

use App\Jobs\GenerateCover;
use App\Jobs\RescanWork;
use App\Models\Mangaka;
use App\Models\Work;
use Illuminate\Support\Facades\Bus;
use Tests\Feature\Scanning\BuildsLibraryFixtures;

uses(BuildsLibraryFixtures::class);

beforeEach(fn () => $this->bootLibrary());
afterEach(fn () => $this->cleanLibrary());

/** @param array<string,string> $entries name => bytes */
function rescanWriteZip(string $path, array $entries): void
{
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0775, true);
    }
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($entries as $name => $bytes) {
        $zip->addFromString($name, $bytes);
    }
    $zip->close();
}

function rescanPng(): string
{
    $img = imagecreatetruecolor(20, 30);
    imagefill($img, 0, 0, imagecolorallocate($img, 10, 20, 30));
    ob_start();
    imagepng($img);

    return (string) ob_get_clean();
}

function runRescan(RescanWork $job): void
{
    $job->handle(app(App\Archive\ArchiveInspector::class), app(App\Tagging\WorkTagSync::class));
}

test('rescan refreshes page info and re-renders the cover for a work with images', function (): void {
    Bus::fake();
    rescanWriteZip($this->libraryPath.'/Circle/Title.zip', ['001.jpg' => rescanPng(), '002.jpg' => rescanPng()]);
    $work = Work::factory()->for(Mangaka::factory())->create([
        'relative_path' => 'Circle/Title.zip',
        'page_count' => 0,
        'entries' => [],
        'cover_path' => null,
        'is_missing' => true,
    ]);

    runRescan(new RescanWork($work->id));

    $work->refresh();
    $this->assertSame(2, $work->page_count);
    $this->assertSame(['001.jpg', '002.jpg'], $work->entries);
    $this->assertFalse($work->is_missing);
    Bus::assertDispatched(GenerateCover::class, fn (GenerateCover $g) => $g->workId === $work->id);
});

test('rescan of a zip with no images clears the cover and queues no cover task', function (): void {
    Bus::fake();
    rescanWriteZip($this->libraryPath.'/Circle/NoImages.zip', ['readme.txt' => 'no images']);
    $work = Work::factory()->for(Mangaka::factory())->create([
        'relative_path' => 'Circle/NoImages.zip',
        'page_count' => 9,
        'entries' => ['stale.jpg'],
        'cover_path' => 'covers/stale.webp',
    ]);

    runRescan(new RescanWork($work->id));

    $work->refresh();
    $this->assertSame(0, $work->page_count);
    $this->assertSame([], $work->entries);
    $this->assertNull($work->cover_path);
    Bus::assertNotDispatched(GenerateCover::class);
});

test('rescan flags the work missing when its file is gone', function (): void {
    Bus::fake();
    $work = Work::factory()->for(Mangaka::factory())->create([
        'relative_path' => 'Circle/Gone.zip', // never written
        'is_missing' => false,
    ]);

    runRescan(new RescanWork($work->id));

    $this->assertTrue($work->refresh()->is_missing);
    Bus::assertNotDispatched(GenerateCover::class);
});

test('rescan no-ops when the work has vanished', function (): void {
    Bus::fake();

    runRescan(new RescanWork(999999));

    $this->assertSame(0, Work::count());
    Bus::assertNotDispatched(GenerateCover::class);
});

test('rescan leaves the work untouched when the archive is unreadable', function (): void {
    Bus::fake();
    rescanWriteZip($this->libraryPath.'/Circle/Bad.zip', []); // create the dir
    file_put_contents($this->libraryPath.'/Circle/Bad.zip', 'not a zip');
    $work = Work::factory()->for(Mangaka::factory())->create([
        'relative_path' => 'Circle/Bad.zip',
        'page_count' => 5,
        'entries' => ['keep.jpg'],
    ]);

    runRescan(new RescanWork($work->id));

    $work->refresh();
    $this->assertSame(5, $work->page_count); // unchanged
    $this->assertSame(['keep.jpg'], $work->entries);
    Bus::assertNotDispatched(GenerateCover::class);
});
