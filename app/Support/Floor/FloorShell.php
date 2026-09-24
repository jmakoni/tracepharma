<?php

namespace App\Support\Floor;

use Illuminate\Http\Request;

/**
 * Server-side floor shell detector (phone / tablet without Desktop view).
 *
 * Viewport comes from cookie {@see self::VIEWPORT_COOKIE} set by first-paint JS.
 * Breakpoints match {@see FloorLayout}. Alpine {@see floor-layout-switch} remains
 * the client redirector; this class is the PHP read of the same rules.
 */
final class FloorShell
{
    public const VIEWPORT_COOKIE = 'tp_viewport';

    public const VIEWPORT_PHONE = 'phone';

    public const VIEWPORT_TABLET = 'tablet';

    public const VIEWPORT_DESKTOP = 'desktop';

    public static function active(?Request $request = null): bool
    {
        $request ??= request();
        $viewport = self::viewport($request);

        if ($viewport === self::VIEWPORT_PHONE) {
            return true;
        }

        if ($viewport === self::VIEWPORT_TABLET) {
            return FloorLayout::cookie() !== FloorLayout::DESKTOP;
        }

        return false;
    }

    public static function viewport(?Request $request = null): ?string
    {
        $request ??= request();
        $value = $request->cookie(self::VIEWPORT_COOKIE);

        return in_array($value, [
            self::VIEWPORT_PHONE,
            self::VIEWPORT_TABLET,
            self::VIEWPORT_DESKTOP,
        ], true) ? $value : null;
    }

    /**
     * Classify width using the same breakpoints as FloorLayout / Alpine switch.
     */
    public static function viewportFromWidth(int $width): string
    {
        if ($width < FloorLayout::PHONE_MAX_PX) {
            return self::VIEWPORT_PHONE;
        }

        if ($width < FloorLayout::DESKTOP_MIN_PX) {
            return self::VIEWPORT_TABLET;
        }

        return self::VIEWPORT_DESKTOP;
    }
}
