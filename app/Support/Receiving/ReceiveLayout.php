<?php

namespace App\Support\Receiving;

use App\Filament\App\Resources\ReceivingSessions\ReceivingSessionResource;
use App\Models\Receiving\ReceivingSession;
use App\Support\Floor\FloorLayout;

/**
 * Desktop vs floor (mobile/tablet) receive surfaces.
 *
 * Cookie {@see FloorLayout::COOKIE}: `desktop` | `floor` forces a layout.
 * With no cookie, client Alpine redirects by viewport (phone/tablet → floor).
 */
final class ReceiveLayout
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
    public static function sessionUrl(ReceivingSession|int|string $record, array $parameters = []): string
    {
        $preferFloor = self::cookie() === self::FLOOR
            || (self::cookie() !== self::DESKTOP && function_exists('floorShell') && floorShell());

        $page = $preferFloor ? 'floor' : 'view';

        return ReceivingSessionResource::getUrl(
            $page,
            array_merge(['record' => $record], $parameters),
            panel: 'app',
        );
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function desktopUrl(ReceivingSession|int|string $record, array $parameters = []): string
    {
        return ReceivingSessionResource::getUrl(
            'view',
            array_merge(['record' => $record], $parameters),
            panel: 'app',
        );
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function floorUrl(ReceivingSession|int|string $record, array $parameters = []): string
    {
        return ReceivingSessionResource::getUrl(
            'floor',
            array_merge(['record' => $record], $parameters),
            panel: 'app',
        );
    }
}
