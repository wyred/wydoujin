<?php

namespace App\Scanning;

use App\Models\Series;
use App\Models\Tag;
use App\Models\Work;
use Illuminate\Support\Facades\DB;

/**
 * Clean-slate wipe for a Full Rescan: deletes every cover file and all derived + curated
 * metadata (tags, the work_tag pivot, rename/merge tombstones, series), and clears each
 * work's cover_path + curation locks. Work rows are kept, so content_hash identity and
 * reading progress survive. / フルスキャン用の全消去（作品行と進捗は保持）。
 */
final class MetadataReset
{
    public function __construct(private readonly string $coversDir)
    {
    }

    public function reset(): void
    {
        // Cover cache: delete outside the transaction — a stray unlink must not roll back the
        // DB wipe. glob() returns false if the dir is missing; treat as nothing to do.
        // 表紙キャッシュ削除（トランザクション外、ディレクトリ無しは何もしない）。
        foreach (glob($this->coversDir.'/*.webp') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        DB::transaction(function (): void {
            // Clear works first so no row references a series we're about to delete (FK-safe).
            // 先に作品を更新し、削除予定のシリーズへの参照を外す。
            Work::query()->update([
                'cover_path' => null,
                'tags_locked' => false,
                'series_locked' => false,
                'series_id' => null,
            ]);
            DB::table('work_tag')->delete();
            Tag::query()->delete();    // merged_into_id is nullOnDelete, so tombstones drop cleanly / 墓石も削除
            Series::query()->delete();
        });
    }
}
