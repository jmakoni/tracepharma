<?php

namespace App\Support\Shipping;

use App\Filament\App\Resources\OutboundShippingSessions\OutboundShippingSessionResource;
use App\Models\Shipping\OutboundShippingSession;
use App\Support\Floor\FloorLayout;

/**
 * Desktop vs floor (mobile/tablet) outbound ship surfaces.
 *
 * Cookie {@see FloorLayout::COOKIE}: `desktop` | `floor` forces a layout.
 * With no cookie, client Alpine redirects by viewport (phone/tablet → floor).
 */
final class ShipLayout
{
    public const COOKIE = FloorLayout::COOKIE;

    public const DESKTOP = FloorLayout::DESKTOP;

    public const FLOOR = FloorLayout::FLOOR;

    /** Tailwind `lg` breakpoint — desktop at/above this width. */
    public const BREAKPOINT_PX = FloorLayout::DESKTOP_MIN_PX;

    public static function cookie(): ?string
    {
        return FloorLayout::cookie();
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
