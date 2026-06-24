<?php

use App\Archive\ArchiveException;
use App\Archive\ArchiveInspector;

/** @param array<string,string> $entries name => contents */
function makeZipArchiveInspector(array $entries): string
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

beforeEach(function () {
    $this->tempFiles = [];
});

afterEach(function () {
    foreach ($this->tempFiles as $f) {
        if (is_file($f)) {
            unlink($f);
        }
    }
});

test('rejects an archive with too many entries', function (): void {
    $zip = makeZipArchiveInspector(['001.jpg' => 'a', '002.jpg' => 'b']);

    expect(fn () => (new ArchiveInspector(maxEntries: 1))->inspect($zip))
        ->toThrow(ArchiveException::class);
});

test('filters images and natural sorts including nested', function (): void {
    $zip = makeZipArchiveInspector([
        '10.jpg' => 'x', '1.jpg' => 'x', '2.jpg' => 'x',
        'cover.png' => 'x', 'ch1/005.webp' => 'x',
        'Thumbs.db' => 'x', 'notes.txt' => 'x',
    ]);

    $r = (new ArchiveInspector())->inspect($zip);

    $this->assertSame(
        ['1.jpg', '2.jpg', '10.jpg', 'ch1/005.webp', 'cover.png'],
        $r->imageEntries,
    );
    $this->assertSame(5, $r->pageCount);
});

test('content hash is order independent but size sensitive', function (): void {
    $a = makeZipArchiveInspector(['a.jpg' => 'A', 'b.jpg' => 'BB']);
    $b = makeZipArchiveInspector(['b.jpg' => 'BB', 'a.jpg' => 'A']);        // different insertion order
    $c = makeZipArchiveInspector(['a.jpg' => 'DIFFERENT', 'b.jpg' => 'BB']); // different size

    $inspector = new ArchiveInspector();
    $hashA = $inspector->inspect($a)->contentHash;
    $hashB = $inspector->inspect($b)->contentHash;
    $hashC = $inspector->inspect($c)->contentHash;

    $this->assertSame($hashA, $hashB);     // order-independent
    $this->assertNotSame($hashA, $hashC);  // size-sensitive
    $this->assertSame(64, strlen($hashA)); // sha256 hex
});

test('zip with no images yields empty entries', function (): void {
    $zip = makeZipArchiveInspector(['readme.txt' => 'hi', 'Thumbs.db' => 'x']);

    $r = (new ArchiveInspector())->inspect($zip);

    $this->assertSame([], $r->imageEntries);
    $this->assertSame(0, $r->pageCount);
    $this->assertSame(64, strlen($r->contentHash));
});

test('throws on unopenable zip', function (): void {
    $bad = tempnam(sys_get_temp_dir(), 'wyd').'.zip';
    $files = $this->tempFiles;
    $files[] = $bad;
    $this->tempFiles = $files;
    file_put_contents($bad, 'this is not a zip');

    $this->expectException(ArchiveException::class);
    (new ArchiveInspector())->inspect($bad);
});
