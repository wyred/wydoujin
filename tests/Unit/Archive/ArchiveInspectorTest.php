<?php

namespace Tests\Unit\Archive;

use App\Archive\ArchiveException;
use App\Archive\ArchiveInspector;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class ArchiveInspectorTest extends TestCase
{
    /** @var string[] temp files to remove */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        parent::tearDown();
    }

    /** @param array<string,string> $entries name => contents */
    private function makeZip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'wyd'); // tempnam creates this file too
        $this->tempFiles[] = $path;                  // register it so it's cleaned up
        $path .= '.zip';
        $this->tempFiles[] = $path;
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return $path;
    }

    public function test_filters_images_and_natural_sorts_including_nested(): void
    {
        $zip = $this->makeZip([
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
    }

    public function test_content_hash_is_order_independent_but_size_sensitive(): void
    {
        $a = $this->makeZip(['a.jpg' => 'A', 'b.jpg' => 'BB']);
        $b = $this->makeZip(['b.jpg' => 'BB', 'a.jpg' => 'A']);        // different insertion order
        $c = $this->makeZip(['a.jpg' => 'DIFFERENT', 'b.jpg' => 'BB']); // different size

        $inspector = new ArchiveInspector();
        $hashA = $inspector->inspect($a)->contentHash;
        $hashB = $inspector->inspect($b)->contentHash;
        $hashC = $inspector->inspect($c)->contentHash;

        $this->assertSame($hashA, $hashB);     // order-independent
        $this->assertNotSame($hashA, $hashC);  // size-sensitive
        $this->assertSame(64, strlen($hashA)); // sha256 hex
    }

    public function test_zip_with_no_images_yields_empty_entries(): void
    {
        $zip = $this->makeZip(['readme.txt' => 'hi', 'Thumbs.db' => 'x']);

        $r = (new ArchiveInspector())->inspect($zip);

        $this->assertSame([], $r->imageEntries);
        $this->assertSame(0, $r->pageCount);
        $this->assertSame(64, strlen($r->contentHash));
    }

    public function test_throws_on_unopenable_zip(): void
    {
        $bad = tempnam(sys_get_temp_dir(), 'wyd').'.zip';
        $this->tempFiles[] = $bad;
        file_put_contents($bad, 'this is not a zip');

        $this->expectException(ArchiveException::class);
        (new ArchiveInspector())->inspect($bad);
    }
}
