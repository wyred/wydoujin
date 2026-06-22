<?php

namespace Tests\Unit\Archive;

use App\Archive\ArchiveException;
use App\Archive\CoverGenerator;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class CoverGeneratorTest extends TestCase
{
    /** @var string[] */
    private array $tempFiles = [];
    private string $coversDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->coversDir = sys_get_temp_dir().'/wyd-covers-'.uniqid();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        if (is_dir($this->coversDir)) {
            array_map('unlink', glob($this->coversDir.'/*') ?: []);
            rmdir($this->coversDir);
        }
        parent::tearDown();
    }

    /** @param array<string,string> $entries */
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

    private function pngBytes(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 100, 150, 200));
        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();

        return $png;
    }

    public function test_generates_resized_webp_cover(): void
    {
        $zip = $this->makeZip(['001.png' => $this->pngBytes(800, 600)]);

        $coverPath = (new CoverGenerator($this->coversDir, 400, 80))
            ->generate($zip, '001.png', 'deadbeef');

        $this->assertSame('covers/deadbeef.webp', $coverPath);
        $file = $this->coversDir.'/deadbeef.webp';
        $this->assertFileExists($file);

        $info = getimagesize($file);
        $this->assertNotFalse($info);
        $this->assertSame(IMAGETYPE_WEBP, $info[2]);
        $this->assertLessThanOrEqual(400, $info[0]); // scaled down from 800
    }

    public function test_throws_when_entry_missing(): void
    {
        $zip = $this->makeZip(['001.png' => $this->pngBytes(100, 100)]);

        $this->expectException(ArchiveException::class);
        (new CoverGenerator($this->coversDir))->generate($zip, 'nope.png', 'h');
    }

    public function test_throws_on_undecodable_image(): void
    {
        $zip = $this->makeZip(['bad.png' => 'not a real image']);

        $this->expectException(ArchiveException::class);
        (new CoverGenerator($this->coversDir))->generate($zip, 'bad.png', 'h');
    }
}
