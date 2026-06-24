<?php

use App\Archive\ArchiveException;
use App\Archive\CoverGenerator;
use App\Archive\ZipPageReader;

/** @param array<string,string> $entries */
function makeZipCoverGenerator(array $entries): string
{
    $path = tempnam(sys_get_temp_dir(), 'wyd'); // tempnam creates this file too
    $files = test()->tempFiles;                  // read-modify-write (array append via test() proxy requires this)
    $files[] = $path;
    test()->tempFiles = $files;
    $path .= '.zip';
    $files = test()->tempFiles;
    $files[] = $path;
    test()->tempFiles = $files;
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($entries as $name => $contents) {
        $zip->addFromString($name, $contents);
    }
    $zip->close();

    return $path;
}

function pngBytesCoverGenerator(int $w, int $h): string
{
    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, imagecolorallocate($img, 100, 150, 200));
    ob_start();
    imagepng($img);
    $png = (string) ob_get_clean();

    return $png;
}

function jpegBytesCoverGenerator(int $w, int $h): string
{
    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, imagecolorallocate($img, 100, 150, 200));
    ob_start();
    imagejpeg($img, null, 85);

    return (string) ob_get_clean();
}

beforeEach(function () {
    $this->tempFiles = [];
    $this->coversDir = sys_get_temp_dir().'/wyd-covers-'.uniqid();
});

afterEach(function () {
    foreach ($this->tempFiles as $f) {
        if (is_file($f)) {
            unlink($f);
        }
    }
    if (is_dir($this->coversDir)) {
        array_map('unlink', glob($this->coversDir.'/*') ?: []);
        rmdir($this->coversDir);
    }
});

test('generates resized webp cover', function (): void {
    $zip = makeZipCoverGenerator(['001.png' => pngBytesCoverGenerator(800, 600)]);

    $coverPath = (new CoverGenerator(new ZipPageReader, $this->coversDir, 400, 80))
        ->generate($zip, '001.png', 'deadbeef');

    $this->assertSame('covers/deadbeef.webp', $coverPath);
    $file = $this->coversDir.'/deadbeef.webp';
    $this->assertFileExists($file);

    $info = getimagesize($file);
    $this->assertNotFalse($info);
    $this->assertSame(IMAGETYPE_WEBP, $info[2]);
    $this->assertLessThanOrEqual(400, $info[0]); // scaled down from 800
});

test('throws when entry missing', function (): void {
    $zip = makeZipCoverGenerator(['001.png' => pngBytesCoverGenerator(100, 100)]);

    $this->expectException(ArchiveException::class);
    (new CoverGenerator(new ZipPageReader, $this->coversDir))->generate($zip, 'nope.png', 'h');
});

test('throws on undecodable image', function (): void {
    $zip = makeZipCoverGenerator(['bad.png' => 'not a real image']);

    $this->expectException(ArchiveException::class);
    (new CoverGenerator(new ZipPageReader, $this->coversDir))->generate($zip, 'bad.png', 'h');
});

test('throws when cover image exceeds the pixel limit', function (): void {
    $zip = makeZipCoverGenerator(['001.png' => pngBytesCoverGenerator(100, 100)]);

    // 100x100 = 10000 px; cap at 1 to trip the pixel-flood guard before GD decodes.
    $this->expectException(ArchiveException::class);
    (new CoverGenerator(new ZipPageReader, $this->coversDir, 400, 80, maxImagePixels: 1))
        ->generate($zip, '001.png', 'h');
});

// The libjpeg shrink-on-load hint: a "WxH" string sized to the target width, keeping
// aspect, so a large JPEG decodes at a reduced scale instead of the full raster.
// shrink-on-loadヒント（縦横比を保ち目標幅に縮小）。
test('shrink-on-load hint scales to the target width keeping aspect', function (): void {
    expect(CoverGenerator::shrinkHint(3000, 4000, 400))->toBe('400x533'); // 4000*400/3000
    expect(CoverGenerator::shrinkHint(1000, 1000, 400))->toBe('400x400');
});

test('shrink-on-load hint never returns a zero dimension', function (): void {
    // A wildly wide banner would round height to 0; clamp to at least 1px.
    expect(CoverGenerator::shrinkHint(8000, 10, 400))->toBe('400x1');
});

// End-to-end over the Imagick driver (the production path). Skipped where the ext is
// absent (local/CI run GD); exercised in the Docker image. / Imagick経路の統合確認。
test('imagick path renders a resized webp from a large jpeg', function (): void {
    $zip = makeZipCoverGenerator(['cover.jpg' => jpegBytesCoverGenerator(2400, 3200)]);

    (new CoverGenerator(new ZipPageReader, $this->coversDir, 400, 80))
        ->generate($zip, 'cover.jpg', 'big');

    $file = $this->coversDir.'/big.webp';
    $this->assertFileExists($file);

    $info = getimagesize($file);
    $this->assertNotFalse($info);
    $this->assertSame(IMAGETYPE_WEBP, $info[2]);
    $this->assertSame(400, $info[0]);       // scaled down to the target width
    $this->assertGreaterThan(0, $info[1]);  // aspect preserved, non-zero height
})->skip(! extension_loaded('imagick'), 'requires the imagick extension (runs in the Docker image)');
