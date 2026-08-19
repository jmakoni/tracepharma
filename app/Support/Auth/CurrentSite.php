<?php

namespace App\Support\Auth;

use App\Models\User;
use App\Support\Receiving\EligibleReceiveSites;

final class CurrentSite
{
    public const SESSION_KEY = 'current_site_id';

    public static function id(): ?int
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return null;
        }

        $sessionId = session(self::SESSION_KEY);

        if ($sessionId !== null && SiteAccess::canAccessSite($user, (int) $sessionId)) {
            return (int) $sessionId;
        }

        $defaultId = SiteAccess::defaultSiteId($user);

        if ($defaultId !== null) {
            session([self::SESSION_KEY => $defaultId]);
        }

        return $defaultId;
    }

    public static function set(int $siteId): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        SiteAccess::assertCanAccessSite($user, $siteId);
        session([self::SESSION_KEY => $siteId]);
    }

    public static function clear(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    /**
     * Prefer the topbar-selected site when valid for the given options map.
     *
     * @param  array<int|string, mixed>|null  $options  keyed by site id; null skips membership check
     */
    public static function preferredId(?int $fallback = null, ?array $options = null): ?int
    {
        $current = self::id();

        if ($current === null) {
            return $fallback;
        }

        if ($options !== null
            && ! array_key_exists($current, $options)
            && ! array_key_exists((string) $current, $options)
        ) {
            return $fallback;
        }

        return $current;
    }

    /**
     * @return array<int, string>
     */
    public static function options(): array
    {
        return EligibleReceiveSites::options();
    }
}
