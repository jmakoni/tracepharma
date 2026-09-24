<?php

namespace App\Support\Floor;

/**
 * Shared desktop vs floor (phone/tablet) chrome preference.
 *
 * Cookie {@see self::COOKIE}: `desktop` | `floor` forces a layout on tablet.
 * Phone (&lt; {@see self::PHONE_MAX_PX}) always uses floor; desktop
 * (≥ {@see self::DESKTOP_MIN_PX}) never auto-routes to floor.
 */
final class FloorLayout
{
    public const COOKIE = 'tp_floor_layout';

    public const DESKTOP = 'desktop';

    public const FLOOR = 'floor';

    /** Tailwind `md` — phone below this width (always floor, no toggle). */
    public const PHONE_MAX_PX = 768;

    /** Tailwind `lg` — desktop at/above this width (never auto-floor). */
    public const DESKTOP_MIN_PX = 1024;

    /** @deprecated Use {@see self::DESKTOP_MIN_PX} */
    public const BREAKPOINT_PX = self::DESKTOP_MIN_PX;

    public static function cookie(): ?string
    {
        $value = request()->cookie(self::COOKIE);

        return in_array($value, [self::DESKTOP, self::FLOOR], true) ? $value : null;
    }
}
