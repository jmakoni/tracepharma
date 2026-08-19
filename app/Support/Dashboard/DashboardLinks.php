<?php

namespace App\Support\Dashboard;

use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Illuminate\Support\Facades\Route;
use Throwable;

final class DashboardLinks
{
    /**
     * @param  class-string<Page>  $page
     */
    public static function pageUrl(string $page): ?string
    {
        try {
            if (! $page::canAccess()) {
                return null;
            }

            return $page::getUrl(panel: 'app');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  class-string<Resource>  $resource
     */
    public static function resourceIndexUrl(string $resource): ?string
    {
        try {
            if (method_exists($resource, 'canAccess') && ! $resource::canAccess()) {
                return null;
            }

            $panel = Filament::getPanel('app');
            $name = $resource::getRouteBaseName($panel).'.index';

            if (! Route::has($name)) {
                return null;
            }

            return $resource::getUrl('index', panel: 'app');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  class-string<Resource>  $resource
     */
    public static function resourceViewUrl(string $resource, int $recordId): ?string
    {
        try {
            if (method_exists($resource, 'canAccess') && ! $resource::canAccess()) {
                return null;
            }

            return $resource::getUrl('view', ['record' => $recordId], panel: 'app');
        } catch (Throwable) {
            return null;
        }
    }
}
