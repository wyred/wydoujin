<?php

use App\Jobs\FinalizeScan;
use App\Jobs\ProcessZip;
use App\Jobs\ScanLibrary;
use App\Models\Scan;
use App\Models\Work;
use App\Scanning\ScannerContract;
use App\Series\SeriesDetectorContract;
use Illuminate\Support\Facades\Bus;
use Tests\Feature\Scanning\BuildsLibraryFixtures;

uses(BuildsLibraryFixtures::class);

beforeEach(fn () => $this->bootLibrary());
afterEach(fn () => $this->cleanLibrary());

test('the scan job carries a long, configurable per-job timeout', function (): void {
    config(['scan.scan_timeout' => 4242]);

    $this->assertSame(4242, (new ScanLibrary('manual'))->timeout);
});

test('the database queue retry_after stays above the scan timeout', function (): void {
    // If retry_after <= a long job's timeout, a still-running job is re-reserved by a second
    // worker mid-flight. / retry_afterがtimeout以下だと多重実行。
    $this->assertGreaterThan(
        (int) config('scan.scan_timeout'),
        (int) config('queue.connections.database.retry_after'),
    );
});

test('scan fans out one ProcessZip task per zip in a batch', function (): void {
    Bus::fake();
    $this->makeDoujin('Z.A.P.', 'A', ['001.jpg']);
    $this->makeDoujin('Z.A.P.', 'B', ['001.jpg', '002.jpg']);

    $scan = Scan::create(['status' => 'queued', 'triggered_by' => 'manual']);
    (new ScanLibrary('manual', $scan->id))->handle(app(ScannerContract::class));

    Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 2
        && $batch->jobs->every(fn ($job) => $job instanceof ProcessZip));
    $this->assertSame('running', $scan->refresh()->status);
});

test('empty library dispatches FinalizeScan directly (no batch)', function (): void {
    Bus::fake();

    $scan = Scan::create(['status' => 'queued', 'triggered_by' => 'manual']);
    (new ScanLibrary('manual', $scan->id))->handle(app(ScannerContract::class));

    Bus::assertNotDispatched(ProcessZip::class);
    Bus::assertDispatched(FinalizeScan::class, fn (FinalizeScan $job) => $job->scanId === $scan->id);
});

test('job runs detection and folds series stats into the scan', function (): void {
    // Two volumes of one series in one mangaka folder; distinct entry lists → distinct hash.
    $this->makeDoujin('Z.A.P.', '[Z.A.P. (ズッキーニ)] 四畳半物語', ['001.jpg']);
    $this->makeDoujin('Z.A.P.', '[Z.A.P. (ズッキーニ)] 四畳半物語 二畳目', ['001.jpg', '002.jpg']);

    $scan = $this->runScan();

    $this->assertSame('completed', $scan->status);
    $this->assertSame(2, $scan->stats['added']);
    $this->assertSame(1, $scan->stats['series_created']);
    $this->assertSame(2, $scan->stats['works_grouped']);
    $this->assertSame(2, Work::whereNotNull('series_id')->count());
});

test('planning failure records a failed scan', function (): void {
    $this->mock(ScannerContract::class, function ($mock) {
        $mock->shouldReceive('planJobs')->once()->andThrow(new RuntimeException('boom'));
    });

    $scan = Scan::create(['status' => 'queued', 'triggered_by' => 'scheduled']);
    (new ScanLibrary('scheduled', $scan->id))->handle(app(ScannerContract::class));

    $scan->refresh();
    $this->assertSame('failed', $scan->status);
    $this->assertSame('boom', $scan->stats['error']);
    $this->assertNotNull($scan->finished_at);
});

test('job updates a pre created scan row when given its id', function (): void {
    $this->makeDoujin('Z.A.P.', '[Z.A.P. (ズッキーニ)] 四畳半物語', ['001.jpg']);

    $scan = $this->runScan();

    $this->assertSame(1, Scan::count()); // updated the existing row, did NOT create a second
    $this->assertSame('completed', $scan->status);
    $this->assertNotNull($scan->started_at);
    $this->assertSame(1, $scan->stats['added']);
});

test('job creates a row when no scan id is given', function (): void {
    $this->makeDoujin('Z.A.P.', '[Z.A.P. (ズッキーニ)] 四畳半物語', ['001.jpg']);

    (new ScanLibrary('scheduled'))->handle(app(ScannerContract::class));

    $scan = Scan::firstOrFail(); // fell back to creating one
    $this->assertSame('completed', $scan->status);
    $this->assertSame('scheduled', $scan->triggered_by);
    $this->assertSame(1, $scan->stats['added']);
});

test('failed() marks the in-flight scan as failed (by id)', function (): void {
    $scan = Scan::create(['status' => 'running', 'triggered_by' => 'scheduled', 'started_at' => now()]);

    (new ScanLibrary('scheduled', $scan->id))->failed(new RuntimeException('worker died'));

    $scan->refresh();
    $this->assertSame('failed', $scan->status);
    $this->assertSame('worker died', $scan->stats['error']);
    $this->assertNotNull($scan->finished_at);
});

test('failed() marks the latest running scan when no id was given', function (): void {
    $scan = Scan::create(['status' => 'running', 'triggered_by' => 'scheduled', 'started_at' => now()]);

    (new ScanLibrary('scheduled'))->failed(null);

    $scan->refresh();
    $this->assertSame('failed', $scan->status);
    $this->assertSame('worker terminated', $scan->stats['error']);
});

test('FinalizeScan no-ops when its scan row has vanished', function (): void {
    (new FinalizeScan(999999))
        ->handle(app(ScannerContract::class), app(SeriesDetectorContract::class));

    $this->assertSame(0, Scan::count());
});

test('FinalizeScan records a failed scan when detection throws', function (): void {
    $scan = Scan::create(['status' => 'running', 'triggered_by' => 'manual', 'started_at' => now()]);
    $this->mock(SeriesDetectorContract::class, function ($mock) {
        $mock->shouldReceive('detect')->once()->andThrow(new RuntimeException('detect boom'));
    });

    (new FinalizeScan($scan->id))
        ->handle(app(ScannerContract::class), app(SeriesDetectorContract::class));

    $scan->refresh();
    $this->assertSame('failed', $scan->status);
    $this->assertSame('detect boom', $scan->stats['error']);
});

test('FinalizeScan failed() marks the scan failed', function (): void {
    $scan = Scan::create(['status' => 'running', 'triggered_by' => 'manual', 'started_at' => now()]);

    (new FinalizeScan($scan->id))->failed(new RuntimeException('died'));

    $scan->refresh();
    $this->assertSame('failed', $scan->status);
    $this->assertSame('died', $scan->stats['error']);
});

test('FinalizeScan failed() falls back to a generic message', function (): void {
    $scan = Scan::create(['status' => 'running', 'triggered_by' => 'manual', 'started_at' => now()]);

    (new FinalizeScan($scan->id))->failed(null);

    $this->assertSame('worker terminated', $scan->refresh()->stats['error']);
});

test('FinalizeScan carries the configurable timeout', function (): void {
    config(['scan.scan_timeout' => 1234]);

    $this->assertSame(1234, (new FinalizeScan(1))->timeout);
});
