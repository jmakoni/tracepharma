<?php

namespace App\Support\Floor;

use App\Filament\App\Pages\AssetTracking;
use App\Filament\App\Pages\BreakPackWorkstation;
use App\Filament\App\Pages\Dashboard;
use App\Filament\App\Pages\FloorFind;
use App\Filament\App\Pages\FloorHome;
use App\Filament\App\Pages\MobileBreakPackWorkstation;
use App\Filament\App\Pages\MobilePackWorkstation;
use App\Filament\App\Pages\MobileUnpackWorkstation;
use App\Filament\App\Pages\MobileVerifyProduct;
use App\Filament\App\Pages\PackWorkstation;
use App\Filament\App\Pages\ScanInWorkstation;
use App\Filament\App\Pages\ScanOutWorkstation;
use App\Filament\App\Pages\UnpackWorkstation;
use App\Filament\App\Pages\VerifyProduct;
use App\Filament\App\Resources\OutboundShippingSessions\OutboundShippingSessionResource;
use App\Filament\App\Resources\ReceivingSessions\ReceivingSessionResource;
use App\Filament\App\Resources\TransferringSessions\TransferringSessionResource;
use Throwable;

/**
 * Desktop ↔ floor URL twins for handheld auto-redirect (Alpine layout-switch).
 */
final class FloorRouteMap
{
    /**
     * @return array{desktopUrl: string, floorUrl: string}|null
     */
    public static function twinForPath(?string $path = null): ?array
    {
        $path = self::normalizePath($path ?? request()->path());

        if (self::isFloorPath($path)) {
            return null;
        }

        try {
            if ($path === '/') {
                return self::pair(
                    Dashboard::getUrl(panel: 'app'),
                    self::launcherUrl(),
                );
            }

            if ($path === '/receiving-sessions') {
                return self::pair(
                    ReceivingSessionResource::getUrl('index', panel: 'app'),
                    ReceivingSessionResource::getUrl('list-floor', panel: 'app'),
                );
            }

            if ($path === '/receiving-sessions/create') {
                $create = ReceivingSessionResource::getUrl('create', panel: 'app');

                return self::pair($create, $create);
            }

            if (preg_match('#^/receiving-sessions/(\d+)$#', $path, $m) === 1) {
                return self::pair(
                    ReceivingSessionResource::getUrl('view', ['record' => $m[1]], panel: 'app'),
                    ReceivingSessionResource::getUrl('floor', ['record' => $m[1]], panel: 'app'),
                );
            }

            if ($path === '/scan-in') {
                $scanFirstFloor = self::scanFirstFloorUrl();
                if ($scanFirstFloor === null) {
                    return null;
                }

                return self::pair(
                    ScanInWorkstation::getUrl(panel: 'app'),
                    $scanFirstFloor,
                );
            }

            if ($path === '/outbound-shipping-sessions') {
                return self::pair(
                    OutboundShippingSessionResource::getUrl('index', panel: 'app'),
                    OutboundShippingSessionResource::getUrl('list-floor', panel: 'app'),
                );
            }

            if ($path === '/outbound-shipping-sessions/create') {
                $create = OutboundShippingSessionResource::getUrl('create', panel: 'app');

                return self::pair($create, $create);
            }

            if (preg_match('#^/outbound-shipping-sessions/(\d+)$#', $path, $m) === 1) {
                return self::pair(
                    OutboundShippingSessionResource::getUrl('view', ['record' => $m[1]], panel: 'app'),
                    OutboundShippingSessionResource::getUrl('floor', ['record' => $m[1]], panel: 'app'),
                );
            }

            if ($path === '/scan-out') {
                $shipFloor = self::shipListFloorUrl();
                if ($shipFloor === null) {
                    return null;
                }

                return self::pair(
                    ScanOutWorkstation::getUrl(panel: 'app'),
                    $shipFloor,
                );
            }

            if ($path === '/transferring-sessions') {
                return self::pair(
                    TransferringSessionResource::getUrl('index', panel: 'app'),
                    TransferringSessionResource::getUrl('list-floor', panel: 'app'),
                );
            }

            if ($path === '/transferring-sessions/receive-floor') {
                $receiveFloor = self::transferReceiveListFloorUrl();
                if ($receiveFloor === null) {
                    return null;
                }

                return self::pair(
                    TransferringSessionResource::getUrl('index', panel: 'app'),
                    $receiveFloor,
                );
            }

            if ($path === '/transferring-sessions/create') {
                $create = TransferringSessionResource::getUrl('create', panel: 'app');

                return self::pair($create, $create);
            }

            if (preg_match('#^/transferring-sessions/(\d+)$#', $path, $m) === 1) {
                return self::pair(
                    TransferringSessionResource::getUrl('view', ['record' => $m[1]], panel: 'app'),
                    TransferringSessionResource::getUrl('floor', ['record' => $m[1]], panel: 'app'),
                );
            }

            if ($path === '/pack-workstation') {
                return self::pair(
                    PackWorkstation::getUrl(panel: 'app'),
                    MobilePackWorkstation::getUrl(panel: 'app'),
                );
            }

            if ($path === '/unpack-workstation') {
                return self::pair(
                    UnpackWorkstation::getUrl(panel: 'app'),
                    MobileUnpackWorkstation::getUrl(panel: 'app'),
                );
            }

            if ($path === '/break-pack-workstation') {
                return self::pair(
                    BreakPackWorkstation::getUrl(panel: 'app'),
                    MobileBreakPackWorkstation::getUrl(panel: 'app'),
                );
            }

            if ($path === '/verify-product') {
                return self::pair(
                    VerifyProduct::getUrl(panel: 'app'),
                    MobileVerifyProduct::getUrl(panel: 'app'),
                );
            }

            if ($path === '/asset-tracking') {
                return self::pair(
                    AssetTracking::getUrl(panel: 'app'),
                    FloorFind::getUrl(panel: 'app'),
                );
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    public static function isFloorPath(?string $path = null): bool
    {
        $path = self::normalizePath($path ?? request()->path());

        if ($path === '/floor') {
            return true;
        }

        return (bool) preg_match('#/(floor)(/|$)#', $path)
            || str_ends_with($path, '/floor')
            || $path === '/pack/floor'
            || $path === '/unpack/floor'
            || $path === '/break-pack/floor'
            || $path === '/verify-product/floor'
            || $path === '/find'
            || str_ends_with($path, '/scan-first-floor');
    }

    public static function launcherUrl(): string
    {
        try {
            return FloorHome::getUrl(panel: 'app');
        } catch (Throwable) {
            return url('/floor');
        }
    }

    public static function receiveListFloorUrl(): ?string
    {
        try {
            if (! ReceivingSessionResource::canAccess()) {
                return null;
            }

            return ReceivingSessionResource::getUrl('list-floor', panel: 'app');
        } catch (Throwable) {
            return null;
        }
    }

    public static function shipListFloorUrl(): ?string
    {
        try {
            if (! OutboundShippingSessionResource::canAccess()) {
                return null;
            }

            return OutboundShippingSessionResource::getUrl('list-floor', panel: 'app');
        } catch (Throwable) {
            return null;
        }
    }

    public static function transferListFloorUrl(): ?string
    {
        return self::transferShipListFloorUrl();
    }

    public static function transferShipListFloorUrl(): ?string
    {
        try {
            if (! TransferringSessionResource::canAccess()) {
                return null;
            }

            return TransferringSessionResource::getUrl('list-floor', panel: 'app');
        } catch (Throwable) {
            return null;
        }
    }

    public static function transferReceiveListFloorUrl(): ?string
    {
        try {
            if (! TransferringSessionResource::canAccess()) {
                return null;
            }

            return TransferringSessionResource::getUrl('receive-floor', panel: 'app');
        } catch (Throwable) {
            return null;
        }
    }

    public static function scanFirstFloorUrl(): ?string
    {
        try {
            if (! ReceivingSessionResource::canAccess()) {
                return null;
            }

            // Floor scan-first list (not desktop Scan In, not the EPCIS file list).
            return ReceivingSessionResource::getUrl('scan-first-floor', panel: 'app');
        } catch (Throwable) {
            return null;
        }
    }

    public static function normalizePath(string $path): string
    {
        $path = '/'.trim($path, '/');

        if ($path === '/') {
            return '/';
        }

        return rtrim($path, '/') ?: '/';
    }

    /**
     * @return array{desktopUrl: string, floorUrl: string}
     */
    private static function pair(string $desktopUrl, string $floorUrl): array
    {
        return [
            'desktopUrl' => $desktopUrl,
            'floorUrl' => $floorUrl,
        ];
    }
}
