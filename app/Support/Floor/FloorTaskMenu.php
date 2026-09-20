<?php

namespace App\Support\Floor;

use App\Enums\TenantProfile;
use App\Filament\App\Pages\BreakPackWorkstation;
use App\Filament\App\Pages\CommissionAllWorkstation;
use App\Filament\App\Pages\DecommissionWorkstation;
use App\Filament\App\Pages\MobileBreakPackWorkstation;
use App\Filament\App\Pages\MobilePackWorkstation;
use App\Filament\App\Pages\MobileUnpackWorkstation;
use App\Filament\App\Pages\MobileVerifyProduct;
use App\Filament\App\Pages\PackWorkstation;
use App\Filament\App\Pages\ReturnWorkstation;
use App\Filament\App\Pages\SaleableReturnWorkstation;
use App\Filament\App\Pages\ScanOutWorkstation;
use App\Filament\App\Pages\UnpackWorkstation;
use App\Filament\App\Pages\VerifyProduct;
use App\Filament\App\Resources\OutboundShippingSessions\OutboundShippingSessionResource;
use App\Support\Dashboard\DashboardLinks;
use App\Support\TenantFeatures;
use Filament\Pages\Page;
use Throwable;

/**
 * Compact floor task launcher tiles for the handheld shell.
 *
 * Never includes Compliance, Master Data, Integrations, Settings, etc.
 * Hide inaccessible tiles — never show a lock.
 */
final class FloorTaskMenu
{
    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'receive' => 'EPCIS Receive',
            'scan-first' => 'Scan first',
            'transfer' => 'Transfer',
            'transfer-receive' => 'Transfer receive',
            'ship' => 'Ship',
            'pack' => 'Pack',
            'unpack' => 'Unpack',
            'break-pack' => 'Break & pack',
            'verify' => 'Verify',
            'returns' => 'Returns',
            'destroy' => 'Destroy',
            'commission' => 'Commission',
        ];
    }

    /**
     * Launcher grid on /floor.
     *
     * @return list<array{key: string, label: string, url: string}>
     */
    public static function launcherTiles(): array
    {
        $features = TenantFeatures::forTenant(tenant());

        if ($features->profile() === TenantProfile::BuyingGroup) {
            return [];
        }

        $tiles = [];

        self::pushReceiveFloorTile($tiles);
        self::pushScanFirstTile($tiles);
        self::pushTransferShipFloorTile($tiles);
        self::pushTransferReceiveFloorTile($tiles);
        self::pushShipFloorTile($tiles);
        self::pushPackFloorTile($tiles);
        self::pushUnpackFloorTile($tiles);
        self::pushFloorPageTile($tiles, 'break-pack', MobileBreakPackWorkstation::class, BreakPackWorkstation::class);
        self::pushVerifyFloorTile($tiles);
        // G3-RET / DEST / COMM: hide until /floor twins exist (desktop nav stays).

        return $tiles;
    }

    /**
     * In-session hamburger (slightly richer: scan-in when accessible).
     *
     * @return list<array{key: string, label: string, url: string}>
     */
    public static function tiles(): array
    {
        $features = TenantFeatures::forTenant(tenant());

        if ($features->profile() === TenantProfile::BuyingGroup) {
            return [];
        }

        $tiles = [];

        self::pushReceiveFloorTile($tiles);
        self::pushScanFirstTile($tiles);
        self::pushShipFloorTile($tiles);
        self::pushTransferShipFloorTile($tiles);
        self::pushTransferReceiveFloorTile($tiles);
        self::pushPackFloorTile($tiles);
        self::pushUnpackFloorTile($tiles);
        self::pushFloorPageTile($tiles, 'break-pack', MobileBreakPackWorkstation::class, BreakPackWorkstation::class);
        self::pushVerifyFloorTile($tiles);
        self::pushReturnsTile($tiles);
        self::pushDestroyTile($tiles);
        self::pushCommissionTile($tiles);

        return $tiles;
    }

    /**
     * @param  list<array{key: string, label: string, url: string}>  $tiles
     */
    private static function pushReceiveFloorTile(array &$tiles): void
    {
        if (! TenantFeatures::forTenant(tenant())->supportsReceiving()) {
            return;
        }

        $url = FloorRouteMap::receiveListFloorUrl();
        if ($url === null) {
            return;
        }

        $tiles[] = [
            'key' => 'receive',
            'label' => self::labels()['receive'],
            'url' => $url,
        ];
    }

    /**
     * @param  list<array{key: string, label: string, url: string}>  $tiles
     */
    private static function pushScanFirstTile(array &$tiles): void
    {
        $url = FloorRouteMap::scanFirstFloorUrl();
        if ($url === null) {
            return;
        }

        $tiles[] = [
            'key' => 'scan-first',
            'label' => self::labels()['scan-first'],
            'url' => $url,
        ];
    }

    /**
     * @param  list<array{key: string, label: string, url: string}>  $tiles
     */
    private static function pushTransferShipFloorTile(array &$tiles): void
    {
        if (! TenantFeatures::forTenant(tenant())->supportsTransferring()) {
            return;
        }

        $url = FloorRouteMap::transferShipListFloorUrl();
        if ($url === null) {
            return;
        }

        $tiles[] = [
            'key' => 'transfer',
            'label' => self::labels()['transfer'],
            'url' => $url,
        ];
    }

    /**
     * @param  list<array{key: string, label: string, url: string}>  $tiles
     */
    private static function pushTransferReceiveFloorTile(array &$tiles): void
    {
        if (! TenantFeatures::forTenant(tenant())->supportsTransferring()) {
            return;
        }

        $url = FloorRouteMap::transferReceiveListFloorUrl();
        if ($url === null) {
            return;
        }

        $tiles[] = [
            'key' => 'transfer-receive',
            'label' => self::labels()['transfer-receive'],
            'url' => $url,
        ];
    }

    /**
     * @param  list<array{key: string, label: string, url: string}>  $tiles
     */
    private static function pushShipFloorTile(array &$tiles): void
    {
        try {
            $features = TenantFeatures::forTenant(tenant());

            if (
                ! $features->supportsOutboundIntegrations()
                && ! $features->supportsPharmacyFullOutbound()
            ) {
                return;
            }

            if (! ScanOutWorkstation::canAccess() && ! OutboundShippingSessionResource::canAccess()) {
                return;
            }

            $url = FloorRouteMap::shipListFloorUrl()
                ?? DashboardLinks::pageUrl(ScanOutWorkstation::class);

            if ($url === null) {
                return;
            }

            $tiles[] = [
                'key' => 'ship',
                'label' => self::labels()['ship'],
                'url' => $url,
            ];
        } catch (Throwable) {
            return;
        }
    }

    /**
     * @param  list<array{key: string, label: string, url: string}>  $tiles
     */
    private static function pushPackFloorTile(array &$tiles): void
    {
        if (! self::showsPackFloorTile()) {
            return;
        }

        self::pushFloorPageTile($tiles, 'pack', MobilePackWorkstation::class, PackWorkstation::class);
    }

    /**
     * @param  list<array{key: string, label: string, url: string}>  $tiles
     */
    private static function pushUnpackFloorTile(array &$tiles): void
    {
        try {
            if (! UnpackWorkstation::canAccess()) {
                return;
            }

            self::pushFloorPageTile($tiles, 'unpack', MobileUnpackWorkstation::class, UnpackWorkstation::class);
        } catch (Throwable) {
            return;
        }
    }

    /**
     * @param  list<array{key: string, label: string, url: string}>  $tiles
     */
    private static function pushVerifyFloorTile(array &$tiles): void
    {
        if (! TenantFeatures::forTenant(tenant())->supportsVrs()) {
            return;
        }

        self::pushFloorPageTile($tiles, 'verify', MobileVerifyProduct::class, VerifyProduct::class);
    }

    /**
     * @param  list<array{key: string, label: string, url: string}>  $tiles
     */
    private static function pushDestroyTile(array &$tiles): void
    {
        try {
            if (! DecommissionWorkstation::canAccess()) {
                return;
            }

            $url = DashboardLinks::pageUrl(DecommissionWorkstation::class);
            if ($url === null) {
                return;
            }

            $tiles[] = [
                'key' => 'destroy',
                'label' => self::labels()['destroy'],
                'url' => $url,
            ];
        } catch (Throwable) {
            return;
        }
    }

    /**
     * @param  list<array{key: string, label: string, url: string}>  $tiles
     */
    private static function pushCommissionTile(array &$tiles): void
    {
        if (! TenantFeatures::forTenant(tenant())->supportsCommissioning()) {
            return;
        }

        try {
            if (! CommissionAllWorkstation::canAccess()) {
                return;
            }

            $url = DashboardLinks::pageUrl(CommissionAllWorkstation::class);
            if ($url === null) {
                return;
            }

            $tiles[] = [
                'key' => 'commission',
                'label' => self::labels()['commission'],
                'url' => $url,
            ];
        } catch (Throwable) {
            return;
        }
    }

    private static function showsPackFloorTile(): bool
    {
        try {
            if (! PackWorkstation::canAccess()) {
                return false;
            }

            $features = TenantFeatures::forTenant(tenant());

            return ! (
                $features->profile() === TenantProfile::Pharmacy
                && ! $features->showsWholesaleOperationsNav()
            );
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Prefer floor page URL when accessible; fall back to desktop workstation.
     *
     * @param  list<array{key: string, label: string, url: string}>  $tiles
     * @param  class-string<Page>  $floorPage
     * @param  class-string<Page>  $desktopPage
     */
    private static function pushFloorPageTile(array &$tiles, string $key, string $floorPage, string $desktopPage): void
    {
        try {
            if (! $desktopPage::canAccess()) {
                return;
            }

            $url = DashboardLinks::pageUrl($floorPage)
                ?? DashboardLinks::pageUrl($desktopPage);

            if ($url === null) {
                return;
            }

            $tiles[] = [
                'key' => $key,
                'label' => self::labels()[$key],
                'url' => $url,
            ];
        } catch (Throwable) {
            return;
        }
    }

    /**
     * @param  list<array{key: string, label: string, url: string}>  $tiles
     */
    private static function pushReturnsTile(array &$tiles): void
    {
        try {
            if (! SaleableReturnWorkstation::canAccess() && ! ReturnWorkstation::canAccess()) {
                return;
            }
        } catch (Throwable) {
            return;
        }

        $url = DashboardLinks::pageUrl(SaleableReturnWorkstation::class)
            ?? DashboardLinks::pageUrl(ReturnWorkstation::class);

        if ($url === null) {
            return;
        }

        $tiles[] = [
            'key' => 'returns',
            'label' => self::labels()['returns'],
            'url' => $url,
        ];
    }
}
