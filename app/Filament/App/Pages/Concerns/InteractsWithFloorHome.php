<?php

namespace App\Filament\App\Pages\Concerns;

use App\Models\Site;
use App\Support\Auth\CurrentSite;
use App\Support\Floor\FloorRouteMap;
use App\Support\Floor\FloorTaskMenu;

trait InteractsWithFloorHome
{
    /**
     * @return list<array{key: string, label: string, url: string}>
     */
    public function launcherTiles(): array
    {
        return FloorTaskMenu::launcherTiles();
    }

    public function currentSiteLabel(): string
    {
        $options = CurrentSite::options();
        $siteId = CurrentSite::id();
        $label = $siteId !== null
            ? ($options[$siteId] ?? $options[(string) $siteId] ?? Site::query()->find($siteId)?->name)
            : null;

        return filled($label) ? (string) $label : 'Site';
    }

    public function currentSiteCode(): string
    {
        $siteId = CurrentSite::id();

        if ($siteId === null) {
            return '—';
        }

        $site = Site::query()->find($siteId);

        if ($site !== null && filled($site->code)) {
            return (string) $site->code;
        }

        return '—';
    }

    public function launcherTileIcon(string $key): string
    {
        return match ($key) {
            'receive' => 'heroicon-o-inbox-arrow-down',
            'scan-first' => 'heroicon-o-qr-code',
            'ship' => 'heroicon-o-truck',
            'transfer' => 'heroicon-o-arrow-up-tray',
            'transfer-receive' => 'heroicon-o-arrow-down-tray',
            'pack' => 'heroicon-o-square-3-stack-3d',
            'unpack' => 'heroicon-o-archive-box-x-mark',
            'verify' => 'heroicon-o-shield-check',
            'returns' => 'heroicon-o-arrow-uturn-left',
            'destroy' => 'heroicon-o-no-symbol',
            'commission' => 'heroicon-o-plus-circle',
            default => 'heroicon-o-squares-2x2',
        };
    }

    public function desktopLauncherUrl(): string
    {
        return url('/');
    }

    public function floorLauncherUrl(): string
    {
        return FloorRouteMap::launcherUrl();
    }
}
