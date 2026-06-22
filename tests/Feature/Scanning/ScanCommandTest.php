<?php

namespace Tests\Feature\Scanning;

use App\Jobs\ScanLibrary;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ScanCommandTest extends TestCase
{
    public function test_command_dispatches_a_manual_scan_job(): void
    {
        Queue::fake();

        $this->artisan('wydoujin:scan')->assertSuccessful();

        Queue::assertPushed(ScanLibrary::class, function (ScanLibrary $job) {
            return $job->triggeredBy === 'manual';
        });
    }
}
