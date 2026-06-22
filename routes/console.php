<?php

use App\Jobs\ScanLibrary;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Periodic library scan (the s6 scheduler runs `schedule:work`). / 定期スキャン。
Schedule::job(new ScanLibrary('scheduled'))->daily();
