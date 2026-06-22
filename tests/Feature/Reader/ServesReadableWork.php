<?php

namespace Tests\Feature\Reader;

use App\Models\Mangaka;
use App\Models\Work;
use ZipArchive;

/** Builds a real on-disk zip + a Work pointing at it, and writes cover files. / 実zip＋Work行を用意。 */
trait ServesReadableWork
{
    private string $libraryPath;
    private string $dataPath;

    private function setUpReaderEnv(): void
    {
        $this->libraryPath = sys_get_temp_dir().'/wyd-rlib-'.uniqid();
        $this->dataPath = sys_get_temp_dir().'/wyd-rdata-'.uniqid();
        mkdir($this->libraryPath, 0775, true);
        mkdir($this->dataPath.'/covers', 0775, true);
        config(['scan.library_path' => $this->libraryPath, 'scan.data_path' => $this->dataPath]);
    }

    private function tearDownReaderEnv(): void
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

    /** Distinct, deterministic bytes per entry so tests can assert the RIGHT page was served. */
    private function entryBytes(string $name): string
    {
        return 'wyd-page-bytes::'.$name;
    }

    /**
     * Build a zip with the given image entries and a Work row whose stored entries match.
     *
     * @param  string[]  $entries
     * @param  array<string,mixed>  $overrides
     */
    private function makeReadableWork(array $entries = ['001.jpg', '002.png'], array $overrides = []): Work
    {
        $mangaka = Mangaka::factory()->create();
        $relative = $mangaka->slug.'/'.uniqid().'.zip';
        $abs = $this->libraryPath.'/'.$relative;
        mkdir(dirname($abs), 0775, true);

        $zip = new ZipArchive();
        $zip->open($abs, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $entry) {
            $zip->addFromString($entry, $this->entryBytes($entry));
        }
        $zip->close();

        return Work::factory()->for($mangaka)->create(array_merge([
            'relative_path' => $relative,
            'filename' => basename($relative),
            'entries' => $entries,
            'page_count' => count($entries),
        ], $overrides));
    }

    /** Write a cover file at <data>/covers/<hash>.webp; return its absolute path. */
    private function writeCover(string $hash, string $bytes = 'wyd-cover-bytes'): string
    {
        $path = $this->dataPath.'/covers/'.$hash.'.webp';
        file_put_contents($path, $bytes);

        return $path;
    }
}
