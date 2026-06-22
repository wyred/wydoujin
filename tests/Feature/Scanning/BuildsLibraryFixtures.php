<?php

namespace Tests\Feature\Scanning;

use ZipArchive;

/** Builds a temp library of <mangaka>/<doujin>.zip with real GD images. / テスト用ライブラリ生成。 */
trait BuildsLibraryFixtures
{
    private string $libraryPath;
    private string $dataPath;

    private function bootLibrary(): void
    {
        $this->libraryPath = sys_get_temp_dir().'/wyd-lib-'.uniqid();
        $this->dataPath = sys_get_temp_dir().'/wyd-data-'.uniqid();
        mkdir($this->libraryPath, 0775, true);
        mkdir($this->dataPath, 0775, true);
        config(['scan.library_path' => $this->libraryPath, 'scan.data_path' => $this->dataPath]);
    }

    private function cleanLibrary(): void
    {
        foreach ([$this->libraryPath ?? null, $this->dataPath ?? null] as $dir) {
            if ($dir !== null && is_dir($dir)) {
                $this->rrmdir($dir);
            }
        }
    }

    private function rrmdir(string $dir): void
    {
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = $dir.'/'.$f;
            is_dir($p) ? $this->rrmdir($p) : unlink($p);
        }
        rmdir($dir);
    }

    /**
     * Create <mangaka>/<filename>.zip with real PNG image entries; return its absolute path.
     * @param string[] $imageEntries
     */
    private function makeDoujin(string $mangaka, string $filename, array $imageEntries = ['001.jpg', '002.jpg']): string
    {
        $dir = $this->libraryPath.'/'.$mangaka;
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = $dir.'/'.$filename.'.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($imageEntries as $entry) {
            $zip->addFromString($entry, $this->pngBytes());
        }
        $zip->close();

        return $path;
    }

    private function pngBytes(): string
    {
        $img = imagecreatetruecolor(20, 30);
        imagefill($img, 0, 0, imagecolorallocate($img, 10, 20, 30));
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }
}
