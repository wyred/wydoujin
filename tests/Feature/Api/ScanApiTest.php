<?php

use App\Jobs\RescanWork;
use App\Jobs\ScanLibrary;
use App\Models\Mangaka;
use App\Models\Scan;
use App\Models\Work;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config(['app.api_token' => 'secret']);
    $this->h = ['Authorization' => 'Bearer secret'];
});

test('post scan dispatches a scan and returns a queued resource', function (): void {
    Queue::fake();

    $this->postJson('/api/v1/scan', [], $this->h)
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.triggered_by', 'manual');

    $scan = Scan::firstOrFail();
    Queue::assertPushed(ScanLibrary::class, fn (ScanLibrary $job) => $job->scanId === $scan->id);
});

test('post scan dedupes an active scan', function (): void {
    Queue::fake();
    $active = Scan::create(['status' => 'running', 'triggered_by' => 'manual']);

    $this->postJson('/api/v1/scan', [], $this->h)
        ->assertStatus(202)
        ->assertJsonPath('data.id', $active->id)
        ->assertJsonPath('data.status', 'running');

    Queue::assertNotPushed(ScanLibrary::class);
});

test('get scan returns the latest scan', function (): void {
    Scan::create(['status' => 'completed', 'triggered_by' => 'scheduled']);

    $this->getJson('/api/v1/scan', $this->h)
        ->assertOk()
        ->assertJsonPath('data.status', 'completed');
});

test('get scan with no history returns null data', function (): void {
    $this->getJson('/api/v1/scan', $this->h)
        ->assertOk()
        ->assertJsonPath('data', null);
});

test('work rescan dispatches RescanWork', function (): void {
    Queue::fake();
    $work = Work::factory()->for(Mangaka::factory())->create();

    $this->postJson("/api/v1/works/{$work->id}/rescan", [], $this->h)->assertStatus(202);

    Queue::assertPushed(RescanWork::class);
});
