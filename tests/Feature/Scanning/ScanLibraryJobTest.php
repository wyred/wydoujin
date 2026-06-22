<?php

namespace Tests\Feature\Scanning;

use App\Jobs\ScanLibrary;
use App\Models\Scan;
use App\Models\Work;
use App\Scanning\ScannerContract;
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

    public function test_job_records_a_completed_scan_with_stats(): void
    {
        $this->makeDoujin('Circle', 'Title', ['001.jpg']);

        (new ScanLibrary('manual'))->handle(app(\App\Scanning\LibraryScanner::class));

        $scan = Scan::firstOrFail();
        $this->assertSame('completed', $scan->status);
        $this->assertSame('manual', $scan->triggered_by);
        $this->assertSame(1, $scan->stats['added']);
        $this->assertNotNull($scan->started_at);
        $this->assertNotNull($scan->finished_at);
        $this->assertSame(1, Work::count());
    }

    public function test_job_records_a_failed_scan_when_the_scanner_throws(): void
    {
        // LibraryScanner is final; mock the ScannerContract interface instead.
        // LibraryScannerはfinalのため、ScannerContractインターフェースをモック。
        $this->mock(ScannerContract::class, function ($mock) {
            $mock->shouldReceive('scan')->once()->andThrow(new \RuntimeException('boom'));
        });

        (new ScanLibrary('scheduled'))->handle(app(ScannerContract::class));

        $scan = Scan::firstOrFail();
        $this->assertSame('failed', $scan->status);
        $this->assertSame('scheduled', $scan->triggered_by);
        $this->assertNotNull($scan->finished_at);
        $this->assertSame('boom', $scan->stats['error']);
    }
}
