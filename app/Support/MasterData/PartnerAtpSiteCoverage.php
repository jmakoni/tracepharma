<?php

namespace App\Support\MasterData;

use App\Enums\AtpVerificationSource;
use App\Enums\SiteAtpReadinessStatus;
use App\Models\Site;
use App\Models\TradingPartner;
use Illuminate\Support\Collection;

/**
 * Per-site ATP coverage for a trading partner.
 *
 * Manufacturer plants are authorized by DECRS. Manufacturer HQ is not monitored
 * for WDD expiry. Every other site must be on the WDD/3PL list and hold a license
 * matching the tenant organization jurisdictions (footprint or preferred receiving state).
 */
final class PartnerAtpSiteCoverage
{
    /**
     * @return Collection<int, array{
     *     site_id: int,
     *     name: string,
     *     place: string,
     *     source: ?string,
     *     source_label: string,
     *     status: string,
     *     badge_label: string,
     *     badge_color: string,
     *     note: ?string
     * }>
     */
    public static function rows(TradingPartner $partner): Collection
    {
        $partner->loadMissing(['sites.atpLicenses', 'sites.tradingPartner']);

        return $partner->sites
            ->sortBy([
                fn (Site $site): int => $site->is_headquarters ? 0 : 1,
                fn (Site $site): string => mb_strtolower((string) $site->name),
            ])
            ->values()
            ->map(fn (Site $site): array => self::row($site));
    }

    /**
     * @return array{
     *     site_id: int,
     *     name: string,
     *     place: string,
     *     source: ?string,
     *     source_label: string,
     *     status: string,
     *     badge_label: string,
     *     badge_color: string,
     *     note: ?string
     * }
     */
    private static function row(Site $site): array
    {
        $source = self::source($site);
        $summary = SiteAtpReadiness::summarize($site);
        /** @var SiteAtpReadinessStatus $status */
        $status = $summary['status'];

        return [
            'site_id' => (int) $site->id,
            'name' => filled($site->name) ? trim((string) $site->name) : 'Site',
            'place' => self::place($site),
            'source' => $source?->value,
            'source_label' => self::sourceLabel($source),
            'status' => $status->value,
            'badge_label' => SiteAtpReadiness::badgeLabel($site),
            'badge_color' => $status->badgeColor(),
            'note' => self::note($site, $status, $source),
        ];
    }

    private static function sourceLabel(?AtpVerificationSource $source): string
    {
        return match ($source) {
            AtpVerificationSource::FdaDecrs => 'FDA DECRS',
            AtpVerificationSource::FdaWdd3pl => 'FDA WDD / 3PL',
            default => 'Needs WDD / 3PL',
        };
    }

    private static function source(Site $site): ?AtpVerificationSource
    {
        if (ManufacturerDecrsAuthorization::matches($site)) {
            return AtpVerificationSource::FdaDecrs;
        }

        if (filled($site->fda_wdd_facility_id)) {
            return AtpVerificationSource::FdaWdd3pl;
        }

        return null;
    }

    private static function note(Site $site, SiteAtpReadinessStatus $status, ?AtpVerificationSource $source): ?string
    {
        if ($source === AtpVerificationSource::FdaDecrs) {
            return 'Manufacturer plant · all states';
        }

        if ($status === SiteAtpReadinessStatus::NotMonitored) {
            return 'Manufacturer HQ · ATP expiry not monitored';
        }

        if ($source === null) {
            return 'Must be on the WDD/3PL list for organization jurisdictions';
        }

        $tenantState = TenantReceivingState::resolve();
        $label = AtpLicenseRelevance::evaluationJurisdictionsLabel();

        if ($status === SiteAtpReadinessStatus::NoLicenses && $label !== 'organization jurisdictions') {
            return 'Needs '.$label.' WDD/3PL license';
        }

        if ($status === SiteAtpReadinessStatus::NeedsReceivingState) {
            return 'No organization jurisdictions configured';
        }

        return SiteAtpReadiness::badgeDescription($site);
    }

    private static function place(Site $site): string
    {
        $parts = array_values(array_filter([
            filled($site->city) ? trim((string) $site->city) : null,
            filled($site->state) ? trim((string) $site->state) : null,
        ], fn (?string $part): bool => filled($part)));

        return implode(', ', $parts);
    }
}
