<?php

namespace App\Support\Epcis;

use App\Models\Site;
use Illuminate\Support\Carbon;
use Throwable;

final class AuthoredEventTimezone
{
    public static function offsetForSite(?Site $site, ?Carbon $at = null): string
    {
        $at ??= now();
        $tzName = $site?->timezone ?: (string) config('app.timezone', 'UTC');

        try {
            return $at->clone()->timezone($tzName)->format('P');
        } catch (Throwable) {
            return $at->clone()->utc()->format('P');
        }
    }
}
