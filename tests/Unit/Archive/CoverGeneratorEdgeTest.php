<?php

use App\Archive\ArchiveException;
use App\Archive\CoverGenerator;

// Line 32: zip->open() returns false → ArchiveException("Cannot open zip: ...").
// / 32行目：zipが開けない → ArchiveException。
test('throws when zip path is not a valid zip', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'wyd').'.zip';
    file_put_contents($path, 'not a zip');
    $this->tempFiles[] = $path;

    $coversDir = sys_get_temp_dir().'/wyd-covers-'.uniqid();

    $this->expectException(ArchiveException::class);
    $this->expectExceptionMessageMatches('/Cannot open zip/');

    (new CoverGenerator($coversDir))->generate($path, 'cover.jpg', 'abc123');
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
