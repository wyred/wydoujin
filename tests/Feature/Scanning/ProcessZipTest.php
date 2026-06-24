<?php

use App\Archive\ArchiveInspector;
use App\Jobs\GenerateCover;
use App\Jobs\ProcessZip;
use App\Models\Mangaka;
use App\Models\Scan;
use App\Models\Work;
use App\Parsing\FilenameParser;
use App\Tagging\WorkTagSync;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Scanning\BuildsLibraryFixtures;

uses(BuildsLibraryFixtures::class);

beforeEach(fn () => $this->bootLibrary());
afterEach(fn () => $this->cleanLibrary());

function runProcessZip(ProcessZip $job): void
{
    $job->handle(app(ArchiveInspector::class), app(FilenameParser::class), app(WorkTagSync::class));
}

test('a new work with images dispatches a GenerateCover task and counts added', function (): void {
    Bus::fake();
    $this->makeDoujin('Circle', 'Title', ['001.jpg']);
    $mangaka = Mangaka::create(['name' => 'Circle', 'slug' => 'circle']);
    $scan = Scan::create(['status' => 'running', 'triggered_by' => 'manual', 'started_at' => now()]);

    runProcessZip(new ProcessZip(
        $scan->id, $mangaka->id, $mangaka->name,
        $this->libraryPath.'/Circle/Title.zip', 'Circle/Title.zip', now()->toIso8601String(),
    ));

    $work = Work::firstOrFail();
    Bus::assertDispatched(GenerateCover::class, fn (GenerateCover $g) => $g->workId === $work->id);
    $this->assertSame(1, (int) $scan->refresh()->added);
});

test('a new work with no images dispatches no cover task', function (): void {
    Bus::fake();
    $dir = $this->libraryPath.'/Circle';
    mkdir($dir, 0775, true);
    $zip = new ZipArchive;
    $zip->open($dir.'/NoImages.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('readme.txt', 'no images');
    $zip->close();
    $mangaka = Mangaka::create(['name' => 'Circle', 'slug' => 'circle']);
    $scan = Scan::create(['status' => 'running', 'triggered_by' => 'manual', 'started_at' => now()]);

    runProcessZip(new ProcessZip(
        $scan->id, $mangaka->id, $mangaka->name,
        $dir.'/NoImages.zip', 'Circle/NoImages.zip', now()->toIso8601String(),
    ));

    Bus::assertNotDispatched(GenerateCover::class);
    $this->assertSame(1, (int) $scan->refresh()->added);
    $this->assertNull(Work::firstOrFail()->cover_path);
});

test('a vanished file is skipped and counts nothing', function (): void {
    Bus::fake();
    $mangaka = Mangaka::create(['name' => 'Circle', 'slug' => 'circle']);
    $scan = Scan::create(['status' => 'running', 'triggered_by' => 'manual', 'started_at' => now()]);

    runProcessZip(new ProcessZip(
        $scan->id, $mangaka->id, $mangaka->name,
        $this->libraryPath.'/Circle/Gone.zip', 'Circle/Gone.zip', now()->toIso8601String(),
    ));

    Bus::assertNotDispatched(GenerateCover::class);
    $scan->refresh();
    $this->assertSame(0, (int) $scan->added + (int) $scan->updated + (int) $scan->moved + (int) $scan->failed);
    $this->assertSame(0, Work::count());
});

test('a content_hash collision is resolved as a move, not a failure', function (): void {
    Bus::fake();
    $this->makeDoujin('Circle', 'Racer', ['001.jpg']);
    $mangaka = Mangaka::create(['name' => 'Circle', 'slug' => 'circle']);
    $scan = Scan::create(['status' => 'running', 'triggered_by' => 'manual', 'started_at' => now()]);

    // Simulate a concurrent worker inserting the same content_hash between our SELECT and
    // INSERT: a creating-hook writes a conflicting row (no events → no recursion).
    $fired = false;
    Event::listen('eloquent.creating: '.Work::class, function (Work $work) use (&$fired, $mangaka) {
        if ($fired) {
            return;
        }
        $fired = true;
        Work::withoutEvents(fn () => Work::factory()->for($mangaka)->create([
            'content_hash' => $work->content_hash,
            'relative_path' => 'Circle/Conflict.zip',
        ]));
    });

    runProcessZip(new ProcessZip(
        $scan->id, $mangaka->id, $mangaka->name,
        $this->libraryPath.'/Circle/Racer.zip', 'Circle/Racer.zip', now()->toIso8601String(),
    ));
    Event::forget('eloquent.creating: '.Work::class);

    $this->assertSame(1, (int) $scan->refresh()->moved);
    $this->assertSame(1, Work::count()); // the racing row, now updated to our path
    $this->assertSame('Circle/Racer.zip', Work::firstOrFail()->relative_path);
});

test('a cancelled batch makes the task no-op', function (): void {
    $this->makeDoujin('Circle', 'Cancelled', ['001.jpg']);
    $mangaka = Mangaka::create(['name' => 'Circle', 'slug' => 'circle']);
    $scan = Scan::create(['status' => 'running', 'triggered_by' => 'manual', 'started_at' => now()]);

    $batch = Bus::batch([])->name('t')->dispatch();
    $batch->cancel();

    $job = new ProcessZip(
        $scan->id, $mangaka->id, $mangaka->name,
        $this->libraryPath.'/Circle/Cancelled.zip', 'Circle/Cancelled.zip', now()->toIso8601String(),
    );
    $job->withBatchId($batch->id);

    runProcessZip($job);

    $this->assertSame(0, Work::count());
    $this->assertSame(0, (int) $scan->refresh()->added);
});
