<?php

namespace App\Scanning;

use App\Archive\ArchiveException;
use App\Archive\ArchiveInspector;
use App\Archive\CoverGenerator;
use App\Models\Mangaka;
use App\Models\Work;
use App\Parsing\FilenameParser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Walks the library and syncs works into the DB by content_hash. / ライブラリを走査しworksを同期。
 */
final class LibraryScanner
{
    public function __construct(
        private readonly ArchiveInspector $inspector,
        private readonly CoverGenerator $covers,
        private readonly FilenameParser $parser,
        private readonly string $libraryPath,
    ) {
    }

    /** @return array<string,int> stats (added, updated, moved, missing, failed) */
    public function scan(): array
    {
        $stats = ['added' => 0, 'updated' => 0, 'moved' => 0, 'missing' => 0, 'failed' => 0];
        $scanStart = Carbon::now();

        foreach ($this->mangakaFolders() as $folder) {
            $mangaka = $this->resolveMangaka(basename($folder));
            foreach (glob($folder.'/*.zip') ?: [] as $zipPath) {
                try {
                    $this->processZip($zipPath, $mangaka, $scanStart, $stats);
                } catch (ArchiveException $e) {
                    $stats['failed']++;
                    report($e); // log and continue / 記録して継続
                }
            }
        }

        // Missing sweep: works not seen this scan. / 未検出のworksをmissingに。
        $stats['missing'] = Work::where('last_seen_at', '<', $scanStart)
            ->where('is_missing', false)
            ->update(['is_missing' => true]);

        return $stats;
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

    /** @param array<string,int> $stats */
    private function processZip(string $zipPath, Mangaka $mangaka, Carbon $scanStart, array &$stats): void
    {
        $relativePath = substr($zipPath, strlen($this->libraryPath) + 1);
        $size = (int) filesize($zipPath);
        $mtime = (int) filemtime($zipPath);

        // Fast incremental skip: same path, unchanged size + mtime. / 高速スキップ。
        $atPath = Work::where('relative_path', $relativePath)->first();
        if ($atPath !== null && (int) $atPath->file_size === $size && (int) $atPath->file_mtime === $mtime) {
            $atPath->update(['last_seen_at' => $scanStart, 'is_missing' => false]);

            return;
        }

        $inspection = $this->inspector->inspect($zipPath);
        $parsed = $this->parser->parse(pathinfo($zipPath, PATHINFO_FILENAME), $mangaka->name);

        $attributes = [
            'mangaka_id' => $mangaka->id,
            'relative_path' => $relativePath,
            'filename' => basename($zipPath),
            'title' => $parsed->title,
            'title_raw' => $parsed->titleRaw,
            'sort_title' => $parsed->sortTitle,
            'event' => $parsed->event,
            'circle' => $parsed->circle,
            'author' => $parsed->author,
            'parody' => $parsed->parody,
            'language' => $parsed->language,
            'flags' => $parsed->flags,
            'page_count' => $inspection->pageCount,
            'entries' => $inspection->imageEntries,
            'file_size' => $size,
            'file_mtime' => $mtime,
            'last_seen_at' => $scanStart,
            'is_missing' => false,
        ];

        $byHash = Work::where('content_hash', $inspection->contentHash)->first();
        if ($byHash !== null) {
            $moved = $byHash->relative_path !== $relativePath;
            $byHash->update($attributes); // keeps content_hash + reading_progress (separate row)
            $stats[$moved ? 'moved' : 'updated']++;

            return;
        }

        $attributes['content_hash'] = $inspection->contentHash;
        $attributes['cover_path'] = $inspection->imageEntries === []
            ? null
            : $this->covers->generate($zipPath, $inspection->imageEntries[0], $inspection->contentHash);

        Work::create($attributes);
        $stats['added']++;
    }
}
