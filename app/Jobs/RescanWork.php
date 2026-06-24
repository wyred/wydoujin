<?php

namespace App\Jobs;

use App\Archive\ArchiveException;
use App\Archive\ArchiveInspector;
use App\Models\Work;
use App\Tagging\WorkTagSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Re-derives a single work from its zip on demand (the per-work "Rescan" action): refreshes
 * page count + image entries + tags and re-renders the cover. Unlike a full scan it ignores
 * the unchanged-file fast-skip, so a work left without a cover/page info can be repaired
 * without touching the rest of the library. / 1作品だけ再走査（表紙・ページ情報の修復）。
 */
final class RescanWork implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** A poisoned file would just re-crash; don't retry. / リトライしない。 */
    public int $tries = 1;

    public function __construct(public readonly int $workId)
    {
    }

    public function handle(ArchiveInspector $inspector, WorkTagSync $tags): void
    {
        $work = Work::find($this->workId);
        if ($work === null) {
            return; // work vanished / 対象消失
        }

        $zipPath = config('scan.library_path').'/'.$work->relative_path;
        if (! is_file($zipPath)) {
            $work->update(['is_missing' => true]); // file gone → flag, keep the row + progress
            return;
        }

        try {
            $inspection = $inspector->inspect($zipPath);
        } catch (ArchiveException $e) {
            report($e); // unreadable archive: leave the work untouched / 記録のみ

            return;
        }

        // Refresh page info; keep content_hash (identity) + reading_progress untouched.
        // ページ情報を更新。content_hashと進捗は保持。
        $work->update([
            'page_count' => $inspection->pageCount,
            'entries' => $inspection->imageEntries,
            'file_size' => (int) filesize($zipPath),
            'file_mtime' => (int) filemtime($zipPath),
            'is_missing' => false,
        ]);

        $tags->sync($work); // re-derive tags (no-op when tags_locked) / タグ再導出（ロック時はスキップ）

        if ($inspection->imageEntries === []) {
            $work->update(['cover_path' => null]); // no image to render a cover from / 画像なし
        } else {
            GenerateCover::dispatch($work->id); // re-render the cover / 表紙を再生成
        }
    }
}
