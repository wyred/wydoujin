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

/** Queued library scan; owns the scans-row lifecycle. / キュー実行のスキャン。scans行のライフサイクル管理。 */
final class ScanLibrary implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $triggeredBy = 'manual')
    {
    }

    public function handle(ScannerContract $scanner, SeriesDetectorContract $detector): void
    {
        $scan = Scan::create([
            'status' => 'running',
            'triggered_by' => $this->triggeredBy,
            'started_at' => now(),
        ]);

        try {
            // Scan first, then group into series; merge both stat sets. / 走査→シリーズ検出→統計併合。
            $stats = array_merge($scanner->scan(), $detector->detect());
            $scan->update(['status' => 'completed', 'stats' => $stats, 'finished_at' => now()]);
        } catch (Throwable $e) {
            // Record the failure; do not re-throw (avoids retry-spamming failed scan rows).
            // 失敗を記録。再スローしない（scans行のリトライスパムを防ぐ）。
            $scan->update(['status' => 'failed', 'stats' => ['error' => $e->getMessage()], 'finished_at' => now()]);
            report($e);
        }
    }
}
