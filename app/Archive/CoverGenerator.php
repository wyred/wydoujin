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
