<?php

namespace Tracepharma\FilamentTableViews;

use Illuminate\Support\ServiceProvider;

class FilamentTableViewsServiceProvider extends ServiceProvider
{
    public static function packagePath(string $path = ''): string
    {
        return dirname(__DIR__).($path !== '' ? DIRECTORY_SEPARATOR.ltrim($path, '/\\') : '');
    }

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'filament-table-views');
    }
}
