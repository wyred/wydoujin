<?php

namespace App\Archive;

use ZipArchive;

/**
 * Reads a zip's central directory (no decompression): content_hash + image entries.
 * zipの中央ディレクトリのみ読む（解凍なし）：content_hash と画像エントリ。
 */
final class ArchiveInspector
{
    public const DEFAULT_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];

    /** @var string[] lowercase image extensions */
    private array $imageExtensions;

    /** @param string[] $imageExtensions */
    public function __construct(array $imageExtensions = self::DEFAULT_IMAGE_EXTENSIONS)
    {
        $this->imageExtensions = array_map('strtolower', $imageExtensions);
    }

    /** @throws ArchiveException if the zip cannot be opened */
    public function inspect(string $zipPath): ArchiveInspection
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new ArchiveException("Cannot open zip: {$zipPath}");
        }

        try {
            $files = [];   // name => size (file entries, for the hash)
            $images = [];  // image entry paths
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false || $this->isDirectory($stat['name'])) {
                    continue;
                }
                $files[$stat['name']] = $stat['size'];
                if ($this->isImage($stat['name'])) {
                    $images[] = $stat['name'];
                }
            }
        } finally {
            $zip->close();
        }

        usort($images, 'strnatcasecmp'); // 1,2,…,10 + nested folders / 自然順

        return new ArchiveInspection(
            contentHash: $this->hashEntries($files),
            imageEntries: $images,
            pageCount: count($images),
        );
    }

    private function isDirectory(string $name): bool
    {
        return str_ends_with($name, '/');
    }

    private function isImage(string $name): bool
    {
        return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), $this->imageExtensions, true);
    }

    /**
     * sha256 over the name-sorted "name\0size" list — order/path-independent identity.
     * 名前順の "name\0size" 列を sha256。順序・パス非依存の同一性（内容ではなくメタdata）。
     *
     * @param array<string,int> $files
     */
    private function hashEntries(array $files): string
    {
        ksort($files, SORT_STRING);
        $canonical = '';
        foreach ($files as $name => $size) {
            $canonical .= $name."\0".$size."\n";
        }

        return hash('sha256', $canonical);
    }
}
