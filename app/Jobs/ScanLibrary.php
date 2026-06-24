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

    /** A scan isn't meaningfully retryable; a poisoned file would just re-crash. / リトライしない。 */
    public int $tries = 1;

    public function __construct(
        public readonly string $triggeredBy = 'manual',
        public readonly ?int $scanId = null,
    ) {
    }

    public function handle(ScannerContract $scanner, SeriesDetectorContract $detector): void
    {
        // Update the row created at dispatch (web "Scan now"); else create one
        // (CLI/scheduler, or if the row vanished). / 起動時に作成済みの行を更新、無ければ作成。
        $scan = $this->scanId ? Scan::find($this->scanId) : null;

        if ($scan) {
            $scan->update(['status' => 'running', 'started_at' => now()]);
        } else {
            $scan = Scan::create([
                'status' => 'running',
                'triggered_by' => $this->triggeredBy,
                'started_at' => now(),
            ]);
        }

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

    /**
     * Terminal failure the try/catch can't see (timeout, or an uncatchable crash on a
     * later attempt): mark the in-flight scan failed so it isn't stuck "running".
     * try/catchで捕捉できない致命的失敗時にscans行をfailedにする（タイムアウト等）。
     */
    public function failed(?Throwable $e): void
    {
        $scan = $this->scanId
            ? Scan::find($this->scanId)
            : Scan::where('status', 'running')->latest()->first();

        $scan?->update([
            'status' => 'failed',
            'stats' => ['error' => $e?->getMessage() ?? 'worker terminated'],
            'finished_at' => now(),
        ]);
    }
}
