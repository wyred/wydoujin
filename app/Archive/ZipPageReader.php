<?php

namespace App\Archive;

use ZipArchive;

/**
 * Reads one entry's raw bytes from a zip (in memory; no disk extraction).
 * zip内の1エントリの生バイトを読む（メモリ上、ディスク展開なし）。
 */
final class ZipPageReader
{
    /** @throws ArchiveException if the zip can't be opened or the entry can't be read */
    public function read(string $zipPath, string $entryName): string
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new ArchiveException("Cannot open zip: {$zipPath}");
        }

        $bytes = $zip->getFromName($entryName);
        $zip->close();

        if ($bytes === false) {
            throw new ArchiveException("Cannot read entry {$entryName} in {$zipPath}");
        }

        return $bytes;
    }
}
