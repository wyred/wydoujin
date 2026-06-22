<?php

namespace Tests\Unit\Archive;

use App\Archive\ArchiveException;
use App\Archive\ZipPageReader;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class ZipPageReaderTest extends TestCase
{
    private string $zipPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->zipPath = sys_get_temp_dir().'/wyd-zpr-'.uniqid().'.zip';
        $zip = new ZipArchive();
        $zip->open($this->zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('001.jpg', 'bytes-of-page-one');
        $zip->addFromString('002.png', 'bytes-of-page-two');
        $zip->close();
    }

    protected function tearDown(): void
    {
        @unlink($this->zipPath);
        parent::tearDown();
    }

    public function test_reads_named_entry_bytes(): void
    {
        $reader = new ZipPageReader();

        $this->assertSame('bytes-of-page-one', $reader->read($this->zipPath, '001.jpg'));
        $this->assertSame('bytes-of-page-two', $reader->read($this->zipPath, '002.png'));
    }

    public function test_throws_when_entry_missing(): void
    {
        $this->expectException(ArchiveException::class);
        (new ZipPageReader())->read($this->zipPath, 'nope.jpg');
    }

    public function test_throws_when_zip_cannot_be_opened(): void
    {
        $this->expectException(ArchiveException::class);
        (new ZipPageReader())->read(sys_get_temp_dir().'/wyd-does-not-exist-'.uniqid().'.zip', '001.jpg');
    }
}
