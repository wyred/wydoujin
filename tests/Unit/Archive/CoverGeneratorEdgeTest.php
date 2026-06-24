<?php

use App\Archive\ArchiveException;
use App\Archive\CoverGenerator;
use App\Archive\ZipPageReader;

// zip->open() returns false → ZipPageReader throws ArchiveException("Cannot open zip: ...").
// / zipが開けない → ArchiveException。
test('throws when zip path is not a valid zip', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'wyd').'.zip';
    file_put_contents($path, 'not a zip');
    $this->tempFiles[] = $path;

    $coversDir = sys_get_temp_dir().'/wyd-covers-'.uniqid();

    $this->expectException(ArchiveException::class);
    $this->expectExceptionMessageMatches('/Cannot open zip/');

    (new CoverGenerator(new ZipPageReader(), $coversDir))->generate($path, 'cover.jpg', 'abc123');
});

beforeEach(function (): void {
    $this->tempFiles = [];
});

afterEach(function (): void {
    foreach ($this->tempFiles as $f) {
        if (is_file($f)) {
            unlink($f);
        }
    }
});
