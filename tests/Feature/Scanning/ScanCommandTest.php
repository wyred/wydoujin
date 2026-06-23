<?php

use App\Jobs\ScanLibrary;
use Illuminate\Support\Facades\Queue;

test('command dispatches a manual scan job', function (): void {
    Queue::fake();

    $this->artisan('wydoujin:scan')->assertSuccessful();

    Queue::assertPushed(ScanLibrary::class, function (ScanLibrary $job) {
        return $job->triggeredBy === 'manual';
    });
});
