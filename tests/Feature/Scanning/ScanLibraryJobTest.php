<?php

namespace Tests\Feature\Scanning;

use App\Jobs\ScanLibrary;
use App\Models\Scan;
use App\Models\Work;
use App\Scanning\ScannerContract;
use App\Series\SeriesDetectorContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScanLibraryJobTest extends TestCase
{
    use RefreshDatabase;
    use BuildsLibraryFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLibrary();
    }

    protected function tearDown(): void
    {
        $this->cleanLibrary();
        parent::tearDown();
    }

    public function test_job_runs_detection_and_folds_series_stats_into_the_scan(): void
    {
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
    }

    public function test_job_records_a_failed_scan_when_the_scanner_throws(): void
    {
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
    }

    public function test_job_updates_a_pre_created_scan_row_when_given_its_id(): void
    {
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
    }

    public function test_job_creates_a_row_when_the_given_scan_id_is_missing(): void
    {
        $this->makeDoujin('Z.A.P.', '[Z.A.P. (ズッキーニ)] 四畳半物語', ['001.jpg']);

        (new ScanLibrary('manual', 999))->handle(
            app(\App\Scanning\LibraryScanner::class),
            app(SeriesDetectorContract::class),
        );

        $scan = Scan::firstOrFail(); // fell back to creating one
        $this->assertSame('completed', $scan->status);
        $this->assertSame(1, $scan->stats['added']);
    }
}
