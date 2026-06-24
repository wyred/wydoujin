<?php

namespace App\Scanning;

use App\Jobs\ProcessZip;
use App\Models\Mangaka;
use App\Models\Work;
use App\Tagging\WorkTagSync;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Plans a library scan into one ProcessZip task per zip, and finalises it once those
 * tasks finish. The per-zip work itself lives in App\Jobs\ProcessZip so a huge library
 * parallelises across workers. / スキャンをzip毎のタスクに分割し、完了後に総仕上げ。
 */
final class LibraryScanner implements ScannerContract
{
    public function __construct(
        private readonly WorkTagSync $tags,
        private readonly string $libraryPath,
    ) {
    }

    /**
     * Resolve every mangaka folder (sequentially, so concurrent tasks never race to create
     * the same Mangaka) and emit one ProcessZip task per zip.
     * マンガ家を逐次解決し（作成競合回避）、zip毎にProcessZipタスクを生成。
     *
     * @return list<ProcessZip>
     */
    public function planJobs(int $scanId, string $scanStartIso): array
    {
        $jobs = [];
        foreach ($this->mangakaFolders() as $folder) {
            $mangaka = $this->resolveMangaka(basename($folder));
            foreach (glob($folder.'/*.zip') ?: [] as $zipPath) {
                $relativePath = substr($zipPath, strlen($this->libraryPath) + 1);
                $jobs[] = new ProcessZip(
                    $scanId,
                    $mangaka->id,
                    $mangaka->name,
                    $zipPath,
                    $relativePath,
                    $scanStartIso,
                );
            }
        }

        return $jobs;
    }

    /**
     * Sweep works untouched this scan as missing and prune orphan tags, atomically.
     * Returns the number flagged missing. / 欠落掃引と孤立タグ削除（原子的）。欠落数を返す。
     */
    public function finalize(string $scanStartIso): int
    {
        $scanStart = Carbon::parse($scanStartIso);

        return DB::transaction(function () use ($scanStart): int {
            $missing = Work::where('last_seen_at', '<', $scanStart)
                ->present()
                ->update(['is_missing' => true]);
            $this->tags->pruneOrphans(); // drop tags no work references / 参照されないタグを削除

            return $missing;
        });
    }

    /** @return string[] absolute paths of top-level mangaka folders */
    private function mangakaFolders(): array
    {
        return glob($this->libraryPath.'/*', GLOB_ONLYDIR) ?: [];
    }

    private function resolveMangaka(string $name): Mangaka
    {
        $existing = Mangaka::where('name', $name)->first();
        if ($existing !== null) {
            return $existing;
        }

        return Mangaka::create(['name' => $name, 'slug' => $this->uniqueSlug($name)]);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'mangaka-'.substr(sha1($name), 0, 12);
        }
        $slug = $base;
        $n = 2;
        while (Mangaka::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }
}
