<?php

namespace App\Jobs;

use App\Models\Scan;
use App\Scanning\ScannerContract;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Throwable;

/**
 * Plans a scan and fans it out: one ProcessZip task per zip, in a batch whose `finally`
 * fires FinalizeScan (missing-sweep + detect + stats). Owns the scans-row lifecycle up to
 * "running"; FinalizeScan closes it out. / スキャンを計画しzip毎タスクへ分配。
 */
final class ScanLibrary implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** A scan isn't meaningfully retryable; a poisoned file would just re-crash. / リトライしない。 */
    public int $tries = 1;

    /**
     * Planning only globs folders + resolves mangaka, but on a huge tree that still beats the
     * queue's default 60s; give it the scan budget. The heavy per-zip work is in the batch.
     * 計画段階のための余裕。重い処理はバッチ側。
     */
    public int $timeout;

    public function __construct(
        public readonly string $triggeredBy = 'manual',
        public readonly ?int $scanId = null,
    ) {
        $this->timeout = (int) config('scan.scan_timeout', 3600);
    }

    public function handle(ScannerContract $scanner): void
    {
        // Update the row created at dispatch (web "Scan now"); else create one
        // (CLI/scheduler, or if the row vanished). / 起動時の行を更新、無ければ作成。
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

        $scanId = $scan->id;
        $scanStartIso = $scan->started_at->toIso8601String();

        try {
            $jobs = $scanner->planJobs($scanId, $scanStartIso);
        } catch (Throwable $e) {
            $scan->update(['status' => 'failed', 'stats' => ['error' => $e->getMessage()], 'finished_at' => now()]);
            report($e);

            return;
        }

        // Empty library: nothing to fan out, finalise straight away. / 空ライブラリは即仕上げ。
        if ($jobs === []) {
            FinalizeScan::dispatch($scanId);

            return;
        }

        // allowFailures: one unexpected per-zip crash must not abort the rest; finally still
        // runs so the scan always closes out. A static callback (not a closure) keeps the
        // batch options plainly serializable. / 1件の失敗で全体を止めない。
        Bus::batch($jobs)
            ->name("library-scan:{$scanId}")
            ->allowFailures()
            ->finally([self::class, 'dispatchFinalize'])
            ->dispatch();
    }

    /** Batch `finally` hook: close out the scan once every ProcessZip task has run. */
    public static function dispatchFinalize(Batch $batch, ?Throwable $e = null): void
    {
        FinalizeScan::dispatch((int) Str::afterLast($batch->name, ':'));
    }

    /**
     * Terminal failure of the planning job itself (timeout/crash before the batch is
     * dispatched): mark the scan failed so it isn't stuck "running".
     * 計画ジョブ自体の致命的失敗時にscans行をfailedにする。
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
