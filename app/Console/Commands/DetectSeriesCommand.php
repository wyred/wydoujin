<?php

namespace App\Console\Commands;

use App\Series\SeriesDetectorContract;
use Illuminate\Console\Command;

/** Detect/refresh auto series per mangaka (synchronous; pure DB). / シリーズ自動検出を即時実行。 */
final class DetectSeriesCommand extends Command
{
    protected $signature = 'wydoujin:series:detect';
    protected $description = 'Detect and refresh auto series (per mangaka)';

    public function handle(SeriesDetectorContract $detector): int
    {
        $stats = $detector->detect();
        $this->info(sprintf(
            'Series detection complete: %d series created, %d works grouped.',
            $stats['series_created'],
            $stats['works_grouped'],
        ));

        return self::SUCCESS;
    }
}
