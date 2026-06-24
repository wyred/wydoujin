<?php

namespace App\Jobs;

use App\Archive\ArchiveException;
use App\Archive\CoverGenerator;
use App\Models\Work;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Renders + caches one work's cover off the scan path, so a huge library's scan
 * never blocks on image decoding. Dispatched per newly-added work; another queue
 * worker picks it up. / 表紙生成をスキャンから切り離す。新規work毎に投入。
 */
final class GenerateCover implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** A cover isn't meaningfully retryable; a poisoned image would just re-crash. / リトライしない。 */
    public int $tries = 1;

    public function __construct(public readonly int $workId)
    {
    }

    public function handle(CoverGenerator $covers): void
    {
        $work = Work::find($this->workId);
        if ($work === null) {
            return; // work vanished before the worker reached it / 対象消失
        }

        $entries = $work->entries ?? [];
        if ($entries === []) {
            return; // nothing to render a cover from / 画像なし
        }

        $zipPath = config('scan.library_path').'/'.$work->relative_path;
        if (! is_file($zipPath)) {
            return; // file gone/moved since the scan enqueued this / ファイル消失
        }

        try {
            $coverPath = $covers->generate($zipPath, $entries[0], $work->content_hash);
            $work->update(['cover_path' => $coverPath]);
        } catch (ArchiveException $e) {
            // A bad cover image must not poison the work row; log and leave cover null.
            // 表紙生成失敗でwork行を汚さない。記録し、cover_pathはnullのまま。
            report($e);
        }
    }
}
