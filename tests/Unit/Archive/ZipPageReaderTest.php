<?php

use App\Archive\ArchiveException;
use App\Archive\ZipPageReader;

beforeEach(function () {
    $this->zipPath = sys_get_temp_dir().'/wyd-zpr-'.uniqid().'.zip';
    $zip = new ZipArchive();
    $zip->open($this->zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('001.jpg', 'bytes-of-page-one');
    $zip->addFromString('002.png', 'bytes-of-page-two');
    $zip->close();
});

afterEach(function () {
    @unlink($this->zipPath);
});

test('reads named entry bytes', function (): void {
    $reader = new ZipPageReader();

    $this->assertSame('bytes-of-page-one', $reader->read($this->zipPath, '001.jpg'));
    $this->assertSame('bytes-of-page-two', $reader->read($this->zipPath, '002.png'));
});

test('throws when entry missing', function (): void {
    $this->expectException(ArchiveException::class);
    (new ZipPageReader())->read($this->zipPath, 'nope.jpg');
});

test('throws when zip cannot be opened', function (): void {
    $this->expectException(ArchiveException::class);
    (new ZipPageReader())->read(sys_get_temp_dir().'/wyd-does-not-exist-'.uniqid().'.zip', '001.jpg');
});

test('throws when entry exceeds the size limit', function (): void {
    // '001.jpg' is 17 bytes; cap at 5 to trip the zip-bomb guard before decompression.
    $this->expectException(ArchiveException::class);
    (new ZipPageReader(maxEntryBytes: 5))->read($this->zipPath, '001.jpg');
});
