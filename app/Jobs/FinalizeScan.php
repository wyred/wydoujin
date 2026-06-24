<?php

namespace App\Jobs;

use App\Models\Scan;
use App\Scanning\ScannerContract;
use App\Series\SeriesDetectorContract;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Closes out a scan once every ProcessZip task is done: missing-sweep + orphan-prune,
 * series detection, then folds the per-task counters into the final stats and marks the
 * scan completed. Runs from the scan batch's `finally`. / バッチ完了後の総仕上げ。
 */
final class FinalizeScan implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Not retryable; a partial re-run would double-count. / リトライしない。 */
    public int $tries = 1;

    /** Detection sweeps the whole library, so give it the scan's generous budget. / 長いタイムアウト。 */
    public int $timeout;

    public function __construct(
        public readonly int $scanId,
    ) {
        $this->timeout = (int) config('scan.scan_timeout', 3600);
    }

    public function handle(ScannerContract $scanner, SeriesDetectorContract $detector): void
    {
        $scan = Scan::find($this->scanId);
        if ($scan === null) {
            return; // row vanished / 行消失
        }

        try {
            // started_at is the scan's instant; ProcessZip stamped touched works with it, so
            // the sweep flags exactly the works this scan didn't see. / 掃引基準は開始時刻。
            $missing = $scanner->finalize($scan->started_at->toIso8601String());
            $series = $detector->detect();                      // group into series / シリーズ検出

            $stats = [
                'added' => (int) $scan->added,
                'updated' => (int) $scan->updated,
                'moved' => (int) $scan->moved,
                'missing' => $missing,
                'failed' => (int) $scan->failed,
            ] + $series;

            $scan->update(['status' => 'completed', 'stats' => $stats, 'finished_at' => now()]);
        } catch (Throwable $e) {
            $scan->update(['status' => 'failed', 'stats' => ['error' => $e->getMessage()], 'finished_at' => now()]);
            report($e);
        }
    }

    /** Terminal failure the try/catch can't see (timeout/crash): don't leave it "running". */
    public function failed(?Throwable $e): void
    {
        Scan::find($this->scanId)?->update([
            'status' => 'failed',
            'stats' => ['error' => $e?->getMessage() ?? 'worker terminated'],
            'finished_at' => now(),
        ]);
    }
}
