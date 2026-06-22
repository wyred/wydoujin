<?php

namespace App\Providers;

use App\Parsing\FilenameParser;
use App\Parsing\NamePattern;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
