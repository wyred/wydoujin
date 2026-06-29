<?php

namespace Tests\Feature\Scanning;

use App\Jobs\ScanLibrary;
use App\Models\Scan;
use App\Scanning\LibraryScanner;
use App\Scanning\MetadataReset;
use ZipArchive;

/** Builds a temp library of <mangaka>/<doujin>.zip with real GD images. / テスト用ライブラリ生成。 */
trait BuildsLibraryFixtures
{
    private string $libraryPath;
    private string $dataPath;

    /**
     * Run a full scan synchronously and return the completed Scan row. Under the test queue
     * (sync) the ProcessZip batch + FinalizeScan run inline, so on return the scan is closed
     * out with its stats. / 同期実行で全パイプラインを走らせ、完了したScanを返す。
     */
    private function runScan(string $triggeredBy = 'manual'): Scan
    {
        $scan = Scan::create(['status' => 'queued', 'triggered_by' => $triggeredBy]);
        (new ScanLibrary($triggeredBy, $scan->id))->handle(app(LibraryScanner::class), app(MetadataReset::class));

        return $scan->refresh();
    }

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
