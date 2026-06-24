<?php

namespace App\Archive;

use ZipArchive;

/**
 * Reads one entry's raw bytes from a zip (in memory; no disk extraction).
 * zip内の1エントリの生バイトを読む（メモリ上、ディスク展開なし）。
 */
final class ZipPageReader
{
    public function __construct(private readonly int $maxEntryBytes = 52_428_800)
    {
    }

    /** @throws ArchiveException if the zip can't be opened, the entry can't be read, or it's oversized */
    public function read(string $zipPath, string $entryName): string
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new ArchiveException("Cannot open zip: {$zipPath}");
        }

        // Reject oversized entries before decompressing into memory (zip-bomb guard).
        // 展開前に巨大エントリを拒否（zip爆弾対策）。
        $stat = $zip->statName($entryName);
        if ($stat !== false && $stat['size'] > $this->maxEntryBytes) {
            $zip->close();
            throw new ArchiveException("Entry {$entryName} exceeds the size limit in {$zipPath}");
        }

        $bytes = $zip->getFromName($entryName);
        $zip->close();

        if ($bytes === false) {
            throw new ArchiveException("Cannot read entry {$entryName} in {$zipPath}");
        }

        return $bytes;
    }
}
