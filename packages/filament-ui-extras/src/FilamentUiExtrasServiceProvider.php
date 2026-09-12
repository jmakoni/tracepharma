<?php

namespace Tracepharma\FilamentUiExtras;

use Illuminate\Support\ServiceProvider;
use Tracepharma\FilamentUiExtras\Macros\InlineLabelPrefixMacros;
use Tracepharma\FilamentUiExtras\Macros\SelectFilterMacros;

class FilamentUiExtrasServiceProvider extends ServiceProvider
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
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'filament-ui-extras');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'filament-ui-extras');

        // Dual sub-navigation: prepend a thin page index override (upgrade-sensitive).
        $this->callAfterResolving('view', function ($view): void {
            $view->prependNamespace(
                'filament-panels',
                __DIR__.'/../resources/views/filament-panels',
            );
        });

        $this->publishes([
            __DIR__.'/../resources/views/filament-panels' => resource_path('views/vendor/filament-panels'),
        ], 'filament-ui-extras-views');

        $this->publishes([
            __DIR__.'/../resources/lang' => lang_path('vendor/filament-ui-extras'),
        ], 'filament-ui-extras-translations');

        SelectFilterMacros::register();
        InlineLabelPrefixMacros::register();
    }
}
