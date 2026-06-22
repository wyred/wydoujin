<?php

namespace App\Providers;

use App\Archive\ArchiveInspector;
use App\Archive\CoverGenerator;
use App\Parsing\FilenameParser;
use App\Parsing\NamePattern;
use App\Scanning\LibraryScanner;
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

        $this->app->singleton(CoverGenerator::class, fn () => new CoverGenerator(
            config('scan.data_path').'/covers',
            config('scan.cover.width'),
            config('scan.cover.quality'),
        ));

        $this->app->bind(LibraryScanner::class, fn ($app) => new LibraryScanner(
            $app->make(ArchiveInspector::class),
            $app->make(CoverGenerator::class),
            $app->make(FilenameParser::class),
            config('scan.library_path'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
