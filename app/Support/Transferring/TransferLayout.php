<?php

namespace App\Support\Transferring;

use App\Filament\App\Resources\TransferringSessions\TransferringSessionResource;
use App\Models\Transferring\TransferringSession;

/**
 * Desktop vs floor (mobile/tablet) transfer surfaces.
 *
 * Cookie {@see self::COOKIE}: `desktop` | `floor` forces a layout.
 * With no cookie, client Alpine redirects by viewport (&lt; lg → floor).
 */
final class TransferLayout
{
    public const COOKIE = 'tp_transfer_layout';

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
    public static function sessionUrl(TransferringSession|int|string $record, array $parameters = []): string
    {
        $page = self::cookie() === self::FLOOR ? 'floor' : 'view';

        return TransferringSessionResource::getUrl(
            $page,
            array_merge(['record' => $record], $parameters),
            panel: 'app',
        );
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function desktopUrl(TransferringSession|int|string $record, array $parameters = []): string
    {
        return TransferringSessionResource::getUrl(
            'view',
            array_merge(['record' => $record], $parameters),
            panel: 'app',
        );
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function floorUrl(TransferringSession|int|string $record, array $parameters = []): string
    {
        return TransferringSessionResource::getUrl(
            'floor',
            array_merge(['record' => $record], $parameters),
            panel: 'app',
        );
    }
}
