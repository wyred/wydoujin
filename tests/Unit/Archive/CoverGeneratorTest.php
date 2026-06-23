<?php

use App\Archive\ArchiveException;
use App\Archive\CoverGenerator;

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
    $zip = new ZipArchive();
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

    $coverPath = (new CoverGenerator($this->coversDir, 400, 80))
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
    (new CoverGenerator($this->coversDir))->generate($zip, 'nope.png', 'h');
});

test('throws on undecodable image', function (): void {
    $zip = makeZipCoverGenerator(['bad.png' => 'not a real image']);

    $this->expectException(ArchiveException::class);
    (new CoverGenerator($this->coversDir))->generate($zip, 'bad.png', 'h');
});
