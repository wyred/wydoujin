<?php

namespace App\Providers;

use App\Archive\ArchiveInspector;
use App\Archive\CoverGenerator;
use App\Archive\ZipPageReader;
use App\Parsing\FilenameParser;
use App\Parsing\NamePattern;
use App\Parsing\PathMetadataResolver;
use App\Scanning\LibraryScanner;
use App\Scanning\ScannerContract;
use App\Series\SeriesDetector;
use App\Series\SeriesDetectorContract;
use App\Tagging\WorkTagSync;
use Illuminate\Support\Facades\Log;
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
            config('scan.limits.max_entries'),
        ));

        $this->app->singleton(ZipPageReader::class, fn () => new ZipPageReader(
            config('scan.limits.max_entry_bytes'),
        ));

        $this->app->singleton(CoverGenerator::class, fn ($app) => new CoverGenerator(
            $app->make(ZipPageReader::class),
            config('scan.data_path').'/covers',
            config('scan.cover.width'),
            config('scan.cover.quality'),
            config('scan.limits.max_image_pixels'),
        ));

        // Scanner is bound, not a singleton: WorkTagSync carries per-scan canonical-id
        // state, so each finalize resolves a fresh one. / スキャン毎の状態のため毎回生成。
        $this->app->bind(LibraryScanner::class, fn ($app) => new LibraryScanner(
            $app->make(WorkTagSync::class),
            $app->make(PathMetadataResolver::class),
            config('scan.library_path'),
        ));

        $this->app->bind(ScannerContract::class, fn ($app) => $app->make(LibraryScanner::class));

        $this->app->bind(SeriesDetectorContract::class, fn ($app) => $app->make(SeriesDetector::class));
    }

    /**
     * Production safety net (defense-in-depth; the primary fix is a correct .env). / 本番の安全策。
     */
    public function boot(): void
    {
        if (! $this->app->environment('production')) {
            return;
        }

        // Never leak env/secrets via a debug error page in production, even if APP_DEBUG was left on.
        // 本番でデバッグ画面による秘密情報漏えいを防ぐ。
        if (config('app.debug')) {
            config(['app.debug' => false]);
            Log::warning('APP_DEBUG was on in production; forced off to prevent secret disclosure.');
        }

        // An open gate is a valid single-user choice, but warn so it stays a conscious one.
        // ゲート無効は許容するが、無自覚を防ぐため警告。
        if ((string) config('app.password') === '') {
            Log::warning('wydoujin is running with no APP_PASSWORD — the app is open to anyone who can reach it.');
        }
    }
}
