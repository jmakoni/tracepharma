<?php

namespace App\Support\Shipping;

use App\Filament\App\Resources\OutboundShippingSessions\OutboundShippingSessionResource;
use App\Models\Shipping\OutboundShippingSession;

/**
 * Desktop vs floor (mobile/tablet) outbound ship surfaces.
 *
 * Cookie {@see self::COOKIE}: `desktop` | `floor` forces a layout.
 * With no cookie, client Alpine redirects by viewport (&lt; lg → floor).
 */
final class ShipLayout
{
    public const COOKIE = 'tp_ship_layout';

    public const DESKTOP = 'desktop';

    public const FLOOR = 'floor';

    /** Tailwind `lg` breakpoint — floor below this width. */
    public const BREAKPOINT_PX = 1024;

    public static function cookie(): ?string
    {
        $value = request()->cookie(self::COOKIE);

        return in_array($value, [self::DESKTOP, self::FLOOR], true) ? $value : null;
    }

    /**
     * Prefer floor URL when the override cookie is floor; otherwise desktop view.
     * Viewport-only preference is handled client-side (Alpine) when cookie is absent.
     *
     * @param  array<string, mixed>  $parameters
     */
    public static function sessionUrl(OutboundShippingSession|int|string $record, array $parameters = []): string
    {
        $page = self::cookie() === self::FLOOR ? 'floor' : 'view';

        return OutboundShippingSessionResource::getUrl(
            $page,
            array_merge(['record' => $record], $parameters),
            panel: 'app',
        );
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function desktopUrl(OutboundShippingSession|int|string $record, array $parameters = []): string
    {
        return OutboundShippingSessionResource::getUrl(
            'view',
            array_merge(['record' => $record], $parameters),
            panel: 'app',
        );
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function floorUrl(OutboundShippingSession|int|string $record, array $parameters = []): string
    {
        return OutboundShippingSessionResource::getUrl(
            'floor',
            array_merge(['record' => $record], $parameters),
            panel: 'app',
        );
    }
}
