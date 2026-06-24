<?php

use App\Jobs\ScanLibrary;
use App\Models\Scan;
use App\Models\Work;
use App\Scanning\ScannerContract;
use App\Series\SeriesDetectorContract;
use Tests\Feature\Scanning\BuildsLibraryFixtures;

uses(BuildsLibraryFixtures::class);

beforeEach(fn () => $this->bootLibrary());
afterEach(fn () => $this->cleanLibrary());

test('job runs detection and folds series stats into the scan', function (): void {
    // Two volumes of one series in one mangaka folder. Distinct entry lists →
    // distinct content_hash (else the 2nd zip looks like a move of the 1st).
    // 同一シリーズの2巻。エントリ数を変えてcontent_hashを別にする（移動誤判定の回避）。
    $this->makeDoujin('Z.A.P.', '[Z.A.P. (ズッキーニ)] 四畳半物語', ['001.jpg']);
    $this->makeDoujin('Z.A.P.', '[Z.A.P. (ズッキーニ)] 四畳半物語 二畳目', ['001.jpg', '002.jpg']);

    (new ScanLibrary('manual'))->handle(
        app(\App\Scanning\LibraryScanner::class),
        app(SeriesDetectorContract::class),
    );

    $scan = Scan::firstOrFail();
    $this->assertSame('completed', $scan->status);
    $this->assertSame(2, $scan->stats['added']);
    $this->assertSame(1, $scan->stats['series_created']);
    $this->assertSame(2, $scan->stats['works_grouped']);
    $this->assertSame(2, Work::whereNotNull('series_id')->count());
});

test('job records a failed scan when the scanner throws', function (): void {
    // LibraryScanner is final; mock the ScannerContract interface instead.
    // LibraryScannerはfinalのため、ScannerContractインターフェースをモック。
    $this->mock(ScannerContract::class, function ($mock) {
        $mock->shouldReceive('scan')->once()->andThrow(new \RuntimeException('boom'));
    });

    (new ScanLibrary('scheduled'))->handle(app(ScannerContract::class), app(SeriesDetectorContract::class));

    $scan = Scan::firstOrFail();
    $this->assertSame('failed', $scan->status);
    $this->assertSame('scheduled', $scan->triggered_by);
    $this->assertNotNull($scan->finished_at);
    $this->assertSame('boom', $scan->stats['error']);
});

test('job updates a pre created scan row when given its id', function (): void {
    $this->makeDoujin('Z.A.P.', '[Z.A.P. (ズッキーニ)] 四畳半物語', ['001.jpg']);

    $scan = Scan::create(['status' => 'queued', 'triggered_by' => 'manual']);

    (new ScanLibrary('manual', $scan->id))->handle(
        app(\App\Scanning\LibraryScanner::class),
        app(SeriesDetectorContract::class),
    );

    $this->assertSame(1, Scan::count()); // updated the existing row, did NOT create a second
    $scan->refresh();
    $this->assertSame('completed', $scan->status);
    $this->assertNotNull($scan->started_at);
    $this->assertSame(1, $scan->stats['added']);
});

test('job creates a row when the given scan id is missing', function (): void {
    $this->makeDoujin('Z.A.P.', '[Z.A.P. (ズッキーニ)] 四畳半物語', ['001.jpg']);

    (new ScanLibrary('manual', 999))->handle(
        app(\App\Scanning\LibraryScanner::class),
        app(SeriesDetectorContract::class),
    );

    $scan = Scan::firstOrFail(); // fell back to creating one
    $this->assertSame('completed', $scan->status);
    $this->assertSame(1, $scan->stats['added']);
});

test('failed() marks the in-flight scan as failed (by id)', function (): void {
    $scan = Scan::create(['status' => 'running', 'triggered_by' => 'scheduled', 'started_at' => now()]);

    (new ScanLibrary('scheduled', $scan->id))->failed(new \RuntimeException('worker died'));

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
