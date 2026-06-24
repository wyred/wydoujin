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
    ) {}

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
            // Prefer Imagick: its pixel cache lives in C memory (bounded by resource
            // limits, spills to disk) so a huge raster can't trip PHP's memory_limit,
            // and libjpeg shrink-on-load decodes big JPEGs at a fraction of full size.
            // GD has neither, so it stays the fallback. / 可能ならImagickを使う。
            $webp = extension_loaded('imagick')
                ? $this->encodeWithImagick($bytes, $info)
                : $this->encodeWithGd($bytes);
            file_put_contents($this->coversDir.'/'.$contentHash.'.webp', $webp);
        } catch (Throwable $e) {
            throw new ArchiveException("Cannot decode cover image for {$entryName}: {$e->getMessage()}", 0, $e);
        }

        return 'covers/'.$contentHash.'.webp';
    }

    /** GD fallback: decodes the full raster, then scales down. / GD（全画素を展開）。 */
    private function encodeWithGd(string $bytes): string
    {
        return (string) (new ImageManager(new Driver))
            ->decode($bytes)
            ->scaleDown(width: $this->width) // never upscales / 拡大はしない
            ->encode(new WebpEncoder($this->quality));
    }

    /**
     * Imagick path: cap ImageMagick's own memory, hint libjpeg to decode the JPEG at a
     * reduced scale (shrink-on-load), then downscale to the target width.
     * Imagickのメモリを制限し、shrink-on-loadで縮小デコードしてから目標幅に縮小。
     *
     * @param  array<int,int>|false  $info  getimagesizefromstring() result (width, height, …)
     */
    private function encodeWithImagick(string $bytes, array|false $info): string
    {
        $im = new \Imagick;

        // ImageMagick's pixel buffers are C-side, not PHP heap; bound them so an
        // oversized raster spills to disk instead of OOM-killing the container.
        $im->setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024);
        $im->setResourceLimit(\Imagick::RESOURCETYPE_MAP, 512 * 1024 * 1024);

        // Shrink-on-load: libjpeg decodes straight to ~target scale (JPEG only; the
        // option is ignored for png/webp/gif). / JPEGのみ有効。
        if ($info !== false && $info[0] > $this->width) {
            $im->setOption('jpeg:size', self::shrinkHint($info[0], $info[1], $this->width));
        }

        $im->readImageBlob($bytes);

        $w = $im->getImageWidth();
        if ($w > $this->width) { // never upscale / 拡大はしない
            $h = max(1, (int) round($im->getImageHeight() * $this->width / $w));
            $im->thumbnailImage($this->width, $h);
        }

        $im->setImageFormat('webp');
        $im->setImageCompressionQuality($this->quality);
        $webp = $im->getImageBlob();

        $im->clear();
        $im->destroy();

        return $webp;
    }

    /**
     * libjpeg shrink-on-load hint ("WxH") sized to the target width, aspect preserved.
     * Lets Imagick decode a large JPEG at a reduced scale instead of the full raster.
     * 目標幅に合わせた縮小ヒント（縦横比維持）。
     */
    public static function shrinkHint(int $srcW, int $srcH, int $targetW): string
    {
        $h = max(1, (int) round($srcH * $targetW / max(1, $srcW)));

        return $targetW.'x'.$h;
    }
}
