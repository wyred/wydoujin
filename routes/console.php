<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use App\Jobs\ScanLibrary;
use Illuminate\Support\Facades\Schedule;

// Periodic library scan (the s6 scheduler runs `schedule:work`). / 定期スキャン。
Schedule::job(new ScanLibrary('scheduled'))->daily();
