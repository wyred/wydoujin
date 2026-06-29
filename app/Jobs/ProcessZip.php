<?php

namespace App\Jobs;

use App\Archive\ArchiveException;
use App\Archive\ArchiveInspector;
use App\Models\Scan;
use App\Models\Work;
use App\Parsing\PathMetadataResolver;
use App\Tagging\WorkTagSync;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Syncs ONE zip into the DB (the old LibraryScanner::processZip, now a task). Dispatched
 * one-per-zip inside the scan batch so a huge library parallelises across workers and no
 * single job can time out. Cover rendering stays a further offloaded job.
 * 1つのzipをDBへ同期するタスク。スキャンバッチ内でzip毎に投入し並列化。
 */
final class ProcessZip implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** A poisoned file would just re-crash; don't retry. / リトライしない。 */
    public int $tries = 1;

    public function __construct(
        public readonly int $scanId,
        public readonly int $mangakaId,
        public readonly string $mangakaName,
        public readonly string $zipPath,
        public readonly string $relativePath,
        public readonly string $scanStartIso,
        public readonly bool $force = false,
    ) {
    }

    public function handle(ArchiveInspector $inspector, PathMetadataResolver $resolver, WorkTagSync $tags): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $scanStart = Carbon::parse($this->scanStartIso);

        try {
            $outcome = $this->process($inspector, $resolver, $tags, $scanStart);
        } catch (ArchiveException $e) {
            report($e); // log and count as failed; the batch carries on / 記録して継続
            $outcome = 'failed';
        }

        // 'skipped' (unchanged file) isn't counted, mirroring the old scanner. / 未変更は集計しない。
        if ($outcome !== 'skipped') {
            Scan::whereKey($this->scanId)->increment($outcome);
        }
    }

    /** @return 'added'|'updated'|'moved'|'skipped'|'failed' */
    private function process(ArchiveInspector $inspector, PathMetadataResolver $resolver, WorkTagSync $tags, Carbon $scanStart): string
    {
        if (! is_file($this->zipPath)) {
            return 'skipped'; // deleted between planning and now / 計画後に消失
        }

        $size = (int) filesize($this->zipPath);
        $mtime = (int) filemtime($this->zipPath);

        // Fast incremental skip: same path, unchanged size + mtime — unless this is a forced
        // rescan, which must re-derive every work. / 高速スキップ（強制時は無効）。
        $atPath = Work::where('relative_path', $this->relativePath)->first();
        if (! $this->force && $atPath !== null && (int) $atPath->file_size === $size && (int) $atPath->file_mtime === $mtime) {
            $atPath->update(['last_seen_at' => $scanStart, 'is_missing' => false]);

            return 'skipped';
        }

        $inspection = $inspector->inspect($this->zipPath);
        // Enriched parse keyed on the relative path (folder author, _series parody). / パス基準の強化解析。
        $parsed = $resolver->resolve($this->relativePath)->parsed;

        $attributes = [
            'mangaka_id' => $this->mangakaId,
            'relative_path' => $this->relativePath,
            'filename' => basename($this->zipPath),
            'title' => $parsed->title,
            'title_raw' => $parsed->titleRaw,
            'sort_title' => $parsed->sortTitle,
            'page_count' => $inspection->pageCount,
            'entries' => $inspection->imageEntries,
            'file_size' => $size,
            'file_mtime' => $mtime,
            'last_seen_at' => $scanStart,
            'is_missing' => false,
        ];

        $hasImages = $inspection->imageEntries !== [];

        $byHash = Work::where('content_hash', $inspection->contentHash)->first();
        if ($byHash !== null) {
            return $this->applyToExisting($byHash, $attributes, $parsed, $tags, $hasImages);
        }

        $attributes['content_hash'] = $inspection->contentHash;

        try {
            $work = Work::create($attributes);
        } catch (UniqueConstraintViolationException) {
            // Two zips with identical content_hash raced; the other won the insert. Re-resolve
            // and treat this one as a move/update so progress stays attached. / 同一hash競合は更新扱い。
            $byHash = Work::where('content_hash', $inspection->contentHash)->firstOrFail();

            return $this->applyToExisting($byHash, $attributes, $parsed, $tags, $hasImages);
        }

        $tags->sync($work, $parsed); // sync metadata tags / メタデータタグを同期

        if ($hasImages) {
            GenerateCover::dispatch($work->id); // offload cover render / 表紙生成は別タスクへ
        }

        return 'added';
    }

    /**
     * Update an existing work matched by content_hash (move or in-place change); keeps its
     * content_hash + reading_progress. / 既存work（移動/更新）へ反映。
     *
     * @param  array<string,mixed>  $attributes
     * @return 'moved'|'updated'
     */
    private function applyToExisting(Work $work, array $attributes, \App\Parsing\ParsedName $parsed, WorkTagSync $tags, bool $hasImages): string
    {
        $moved = $work->relative_path !== $this->relativePath;
        $work->update($attributes);
        $tags->sync($work, $parsed);

        // A forced rescan cleared the cover cache, so re-render existing works' covers too
        // (the normal scan only covers newly-added works). / 強制時は既存作品の表紙も再生成。
        if ($this->force && $hasImages) {
            GenerateCover::dispatch($work->id);
        }

        return $moved ? 'moved' : 'updated';
    }
}
