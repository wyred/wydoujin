<?php

use App\Jobs\ScanLibrary;
use App\Models\Mangaka;
use App\Models\Scan;
use App\Models\Work;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->withoutVite();
});

test('maintenance page renders nav history and missing works', function (): void {
    $m = Mangaka::factory()->create(['name' => 'GhostMangaka']);
    Work::factory()->for($m)->create(['title' => 'MissingWork', 'sort_title' => 'MissingWork', 'is_missing' => true]);
    Work::factory()->for($m)->create(['title' => 'PresentWork', 'sort_title' => 'PresentWork', 'is_missing' => false]);
    Scan::create(['status' => 'completed', 'triggered_by' => 'manual', 'stats' => ['added' => 2], 'started_at' => now(), 'finished_at' => now()]);

    $this->get('/maintenance')->assertOk()
        ->assertSee('href="/maintenance"', false) // nav link
        ->assertSee('MissingWork')                // server-rendered missing list
        ->assertDontSee('PresentWork')            // not-missing excluded
        ->assertSee('Missing works')              // section heading
        ->assertSee('completed');                 // latest scan embedded for Alpine
});

test('empty states', function (): void {
    $this->get('/maintenance')->assertOk()
        ->assertSee('No scans yet')
        ->assertSee('No missing works');
});

test('scan creates a queued row and dispatches with its id', function (): void {
    Queue::fake();

    $this->postJson('/scan')->assertStatus(202)->assertJsonPath('scan.status', 'queued');

    $scan = Scan::firstOrFail();
    $this->assertSame('queued', $scan->status);
    Queue::assertPushed(ScanLibrary::class, fn (ScanLibrary $job) => $job->scanId === $scan->id && $job->triggeredBy === 'manual');
});

test('scan does not double dispatch when one is active', function (): void {
    Queue::fake();
    $active = Scan::create(['status' => 'running', 'triggered_by' => 'manual', 'started_at' => now()]);

    $this->postJson('/scan')->assertStatus(202)->assertJsonPath('scan.id', $active->id);

    $this->assertSame(1, Scan::count()); // no new row
    Queue::assertNothingPushed();
});

test('scan does not double dispatch when a queued scan exists', function (): void {
    Queue::fake();
    $queued = Scan::create(['status' => 'queued', 'triggered_by' => 'manual']);

    $this->postJson('/scan')->assertStatus(202)->assertJsonPath('scan.id', $queued->id);

    $this->assertSame(1, Scan::count());
    Queue::assertNothingPushed();
});

test('status returns latest scan or null', function (): void {
    $this->getJson('/maintenance/status')->assertOk()->assertJsonPath('scan', null);

    Scan::create(['status' => 'completed', 'triggered_by' => 'manual', 'stats' => ['added' => 3], 'started_at' => now(), 'finished_at' => now()]);

    $this->getJson('/maintenance/status')->assertOk()
        ->assertJsonPath('scan.status', 'completed')
        ->assertJsonPath('scan.stats.added', 3);
});

test('full rescan creates a queued full scan and dispatches a forced ScanLibrary', function (): void {
    Queue::fake();

    $this->postJson('/maintenance/full-rescan')->assertStatus(202)
        ->assertJsonPath('scan.status', 'queued')
        ->assertJsonPath('scan.triggered_by', 'full');

    $scan = Scan::firstOrFail();
    Queue::assertPushed(ScanLibrary::class, fn (ScanLibrary $job) =>
        $job->scanId === $scan->id && $job->triggeredBy === 'full' && $job->force === true);
});

test('maintenance page shows the Full Rescan control and its warning copy', function (): void {
    $this->get('/maintenance')->assertOk()
        ->assertSee('Full Rescan')
        ->assertSee("this can't be undone")
        ->assertSee('The entire cover-image cache')
        ->assertSee('Your files and reading progress are kept.');
});

test('full rescan does not double dispatch when a scan is active', function (): void {
    Queue::fake();
    $active = Scan::create(['status' => 'running', 'triggered_by' => 'manual', 'started_at' => now()]);

    $this->postJson('/maintenance/full-rescan')->assertStatus(202)->assertJsonPath('scan.id', $active->id);

    $this->assertSame(1, Scan::count());
    Queue::assertNothingPushed();
});
