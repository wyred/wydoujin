<?php

namespace App\Providers;

use App\Archive\ArchiveInspector;
use App\Archive\CoverGenerator;
use App\Archive\ZipPageReader;
use App\Parsing\FilenameParser;
use App\Parsing\NamePattern;
use App\Scanning\LibraryScanner;
use App\Scanning\ScannerContract;
use App\Series\SeriesDetector;
use App\Series\SeriesDetectorContract;
use App\Tagging\WorkTagSync;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(FilenameParser::class, function ($app) {
            $patterns = array_map(
                fn (string $class): NamePattern => $app->make($class),
                config('parser.patterns', []),
            );

            return new FilenameParser($patterns);
        });

        $this->app->singleton(ArchiveInspector::class, fn () => new ArchiveInspector(
            config('scan.image_extensions'),
        ));

        $this->app->singleton(CoverGenerator::class, fn ($app) => new CoverGenerator(
            $app->make(ZipPageReader::class),
            config('scan.data_path').'/covers',
            config('scan.cover.width'),
            config('scan.cover.quality'),
        ));

        // Scanner/detector are bound, not singletons: each carries per-scan state
        // (e.g. WorkTagSync's canonical-id cache), so every scan resolves a fresh one.
        // スキャナ/検出器はスキャン毎の状態を持つため毎回生成（シングルトンにしない）。
        $this->app->bind(LibraryScanner::class, fn ($app) => new LibraryScanner(
            $app->make(ArchiveInspector::class),
            $app->make(CoverGenerator::class),
            $app->make(FilenameParser::class),
            $app->make(WorkTagSync::class),
            config('scan.library_path'),
        ));

        $this->app->bind(ScannerContract::class, fn ($app) => $app->make(LibraryScanner::class));

        $this->app->bind(SeriesDetectorContract::class, fn ($app) => $app->make(SeriesDetector::class));
    }
}
