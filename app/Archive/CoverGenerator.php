<?php

namespace App\Archive;

use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Renders a resized webp cover from one zip entry. / zip内1エントリから縮小webp表紙を生成。
 */
final class CoverGenerator
{
    public function __construct(
        private readonly ZipPageReader $reader,
        private readonly string $coversDir,
        private readonly int $width = 400,
        private readonly int $quality = 80,
        private readonly int $maxImagePixels = 40_000_000,
    ) {
    }

    /**
     * @return string the cover path relative to the data root, e.g. covers/<hash>.webp
     *
     * @throws ArchiveException if the entry can't be read or the image can't be decoded
     */
    public function generate(string $zipPath, string $entryName, string $contentHash): string
    {
        $bytes = $this->reader->read($zipPath, $entryName);

        // Reject pixel-flood / decompression-bomb images before GD allocates the full
        // bitmap (~width*height*4 bytes). getimagesizefromstring reads only the header.
        // GDがビットマップを確保する前に巨大画像を拒否（ヘッダのみ読む）。
        $info = getimagesizefromstring($bytes);
        if ($info !== false && ($info[0] * $info[1]) > $this->maxImagePixels) {
            throw new ArchiveException("Cover image for {$entryName} exceeds the pixel limit ({$info[0]}x{$info[1]})");
        }

        if (! is_dir($this->coversDir)) {
            mkdir($this->coversDir, 0775, true);
        }

        try {
            $encoded = (new ImageManager(new Driver()))
                ->decode($bytes)
                ->scaleDown(width: $this->width) // never upscales / 拡大はしない
                ->encode(new WebpEncoder($this->quality));
            $encoded->save($this->coversDir.'/'.$contentHash.'.webp');
        } catch (Throwable $e) {
            throw new ArchiveException("Cannot decode cover image for {$entryName}: {$e->getMessage()}", 0, $e);
        }

        return 'covers/'.$contentHash.'.webp';
    }
}
