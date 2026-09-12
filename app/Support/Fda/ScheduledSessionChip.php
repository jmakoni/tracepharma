<?php

namespace App\Support\Fda;

use App\Models\Site;
use App\Models\TradingPartner;

final class ScheduledSessionChip
{
    /**
     * Session-level DEA schedule chip, e.g. "CII · No DEA on seller".
     */
    public static function label(?string $highest, bool $missingDea, string $missingSuffix): ?string
    {
        if ($highest === null || $highest === '') {
            return null;
        }

        if (! $missingDea) {
            return $highest;
        }

        $suffix = trim($missingSuffix);

        return $suffix === '' ? $highest : $highest.' · '.$suffix;
    }

    /**
     * DaisyUI / Filament badge color for schedule (missing-DEA does not change color).
     */
    public static function badgeColor(?string $highest): ?string
    {
        return match ($highest) {
            'CII' => 'danger',
            'CIII', 'CIV', 'CV' => 'warning',
            default => null,
        };
    }

    public static function partyHasDea(?int $tradingPartnerId): bool
    {
        if ($tradingPartnerId === null) {
            return false;
        }

        $partner = TradingPartner::query()->find($tradingPartnerId);
        if ($partner === null) {
            return false;
        }

        if (DeaRegistration::normalize($partner->dea_number) !== null) {
            return true;
        }

        return Site::query()
            ->where('trading_partner_id', $tradingPartnerId)
            ->whereNotNull('dea_number')
            ->where('dea_number', '!=', '')
            ->get(['dea_number'])
            ->contains(fn (Site $site): bool => DeaRegistration::normalize($site->dea_number) !== null);
    }

    public static function siteHasDea(?Site $site): bool
    {
        return $site !== null && DeaRegistration::normalize($site->dea_number) !== null;
    }
}
