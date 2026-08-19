<?php

namespace App\Support\MasterData;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Expiration windows for ATP licenses, shared by tenant and catalog queries so
 * a license falls in exactly one of expired / expiring / unknown expiry / active.
 *
 * Mirrors the in-memory comparisons in {@see SiteAtpReadiness}: a license that
 * expires today is still in force, and a missing date is never "active".
 */
final class AtpLicenseExpiry
{
    public const EXPIRING_WINDOW_DAYS = 90;

    public static function today(): Carbon
    {
        return now()->startOfDay();
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function expired(Builder $query): Builder
    {
        return $query
            ->whereNotNull('license_expiration_date')
            ->whereDate('license_expiration_date', '<', self::today());
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function expiringSoon(Builder $query): Builder
    {
        $today = self::today();

        return $query
            ->whereNotNull('license_expiration_date')
            ->whereDate('license_expiration_date', '>=', $today)
            ->whereDate('license_expiration_date', '<=', $today->copy()->addDays(self::EXPIRING_WINDOW_DAYS));
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function unknownExpiry(Builder $query): Builder
    {
        return $query->whereNull('license_expiration_date');
    }
}
