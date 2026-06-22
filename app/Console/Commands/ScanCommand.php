<?php

namespace App\Console\Commands;

use App\Jobs\ScanLibrary;
use Illuminate\Console\Command;

/** Queue a manual library scan. / 手動のライブラリスキャンをキューに入れる。 */
final class ScanCommand extends Command
{
    protected $signature = 'wydoujin:scan';
    protected $description = 'Queue a scan of the library';

    public function handle(): int
    {
        ScanLibrary::dispatch('manual');
        $this->info('Library scan queued.');

        return self::SUCCESS;
    }
}
