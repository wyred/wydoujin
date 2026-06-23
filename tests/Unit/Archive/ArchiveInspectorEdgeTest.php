<?php

use App\Archive\ArchiveInspector;

// Line 38: statIndex returns false for a directory entry → continue (skip it).
// ZipArchive includes directory entries (trailing "/") which statIndex can return;
// the inspector must skip them. / ディレクトリエントリはスキップされること。
test('directory entries inside zip are skipped via statIndex continue branch', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'wyd').'.zip';
    $this->tempFiles[] = $path;

    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    // addEmptyDir adds a directory entry (statIndex returns name ending with "/").
    $zip->addEmptyDir('subdir/');
    $zip->addFromString('subdir/001.jpg', 'x');
    $zip->addFromString('subdir/002.jpg', 'x');
    $zip->close();

    $r = (new ArchiveInspector())->inspect($path);

    // Directory entry skipped; 2 images found.
    // / ディレクトリエントリはスキップ；画像2件。
    $this->assertSame(2, $r->pageCount);
    $this->assertNotContains('subdir/', $r->imageEntries);
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
