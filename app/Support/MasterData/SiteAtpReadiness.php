<?php

namespace App\Support\MasterData;

use App\Enums\FacilityType;
use App\Enums\PartnerType;
use App\Enums\SiteAtpReadinessStatus;
use App\Models\AtpLicense;
use App\Models\Site;
use App\Models\TradingPartner;
use App\Support\Fda\FdaTenantLink;
use App\Support\Places\UsState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use WeakMap;

final class SiteAtpReadiness
{
    /**
     * @var WeakMap<Site, array<string, mixed>>|null
     */
    private static ?WeakMap $summaries = null;

    /**
     * @return array{
     *     total: int,
     *     expired_total: int,
     *     relevant_total: int,
     *     relevant_expired: int,
     *     relevant_expiring_within_90_days: int,
     *     relevant_unknown_expiry: int,
     *     tenant_state: ?string,
     *     status: SiteAtpReadinessStatus,
     *     facility_types: Collection<int, FacilityType>
     * }
     */
    public static function summarize(Site $site): array
    {
        $summaries = self::$summaries ??= new WeakMap;

        /** @phpstan-ignore-next-line offsetAccess.notFound */
        return $summaries[$site] ??= self::computeSummary($site);
    }

    public static function forget(?Site $site = null): void
    {
        if (self::$summaries === null) {
            return;
        }

        if ($site === null) {
            self::$summaries = null;

            return;
        }

        unset(self::$summaries[$site]);
    }

    /**
     * @return array{
     *     total: int,
     *     expired_total: int,
     *     relevant_total: int,
     *     relevant_expired: int,
     *     relevant_expiring_within_90_days: int,
     *     relevant_unknown_expiry: int,
     *     tenant_state: ?string,
     *     status: SiteAtpReadinessStatus,
     *     facility_types: Collection<int, FacilityType>
     * }
     */
    private static function computeSummary(Site $site): array
    {
        $tenantState = TenantReceivingState::resolve();

        if (ManufacturerDecrsAuthorization::matches($site)) {
            return [
                'total' => 0,
                'expired_total' => 0,
                'relevant_total' => 0,
                'relevant_expired' => 0,
                'relevant_expiring_within_90_days' => 0,
                'relevant_unknown_expiry' => 0,
                'tenant_state' => $tenantState,
                'status' => SiteAtpReadinessStatus::FdaRegistered,
                'facility_types' => collect(),
            ];
        }

        if (AtpLicenseRelevance::isManufacturerHeadquarters($site)) {
            $partner = $site->relationLoaded('tradingPartner')
                ? $site->tradingPartner
                : $site->tradingPartner()->first();

            $status = $partner instanceof TradingPartner && FdaTenantLink::organizationId($partner) !== null
                ? SiteAtpReadinessStatus::FdaRegistered
                : SiteAtpReadinessStatus::NotMonitored;

            return [
                'total' => 0,
                'expired_total' => 0,
                'relevant_total' => 0,
                'relevant_expired' => 0,
                'relevant_expiring_within_90_days' => 0,
                'relevant_unknown_expiry' => 0,
                'tenant_state' => $tenantState,
                'status' => $status,
                'facility_types' => collect(),
            ];
        }

        $licenses = self::licenses($site);
        $footprint = AtpLicenseRelevance::evaluationJurisdictionKeys();

        $today = AtpLicenseExpiry::today();
        $in90Days = $today->copy()->addDays(AtpLicenseExpiry::EXPIRING_WINDOW_DAYS);

        $expiredTotal = $licenses
            ->filter(fn (AtpLicense $license): bool => self::isExpired($license, $today))
            ->count();

        $relevantLicenses = AtpLicenseRelevance::filterToFootprint($licenses, $footprint);

        $relevantExpired = $relevantLicenses
            ->filter(fn (AtpLicense $license): bool => self::isExpired($license, $today))
            ->count();

        $relevantExpiringWithin90Days = $relevantLicenses
            ->filter(fn (AtpLicense $license): bool => self::isExpiringWithin90Days($license, $today, $in90Days))
            ->count();

        $relevantUnknownExpiry = $relevantLicenses
            ->filter(fn (AtpLicense $license): bool => $license->license_expiration_date === null)
            ->count();

        $status = self::resolveStatus(
            $footprint,
            $relevantLicenses->count(),
            $relevantExpired,
            $relevantExpiringWithin90Days,
            $relevantUnknownExpiry,
        );

        $facilityTypesSource = $footprint !== [] ? $relevantLicenses : $licenses;

        return [
            'total' => $licenses->count(),
            'expired_total' => $expiredTotal,
            'relevant_total' => $relevantLicenses->count(),
            'relevant_expired' => $relevantExpired,
            'relevant_expiring_within_90_days' => $relevantExpiringWithin90Days,
            'relevant_unknown_expiry' => $relevantUnknownExpiry,
            'tenant_state' => $tenantState,
            'status' => $status,
            'facility_types' => $facilityTypesSource
                ->pluck('facility_type')
                ->filter()
                ->unique()
                ->values(),
        ];
    }

    public static function badgeLabel(Site $site): string
    {
        $stats = self::summarize($site);

        if ($stats['status'] === SiteAtpReadinessStatus::FdaRegistered) {
            return 'Ready';
        }

        if ($stats['status'] === SiteAtpReadinessStatus::NotMonitored) {
            return 'N/A';
        }

        if ($stats['status'] === SiteAtpReadinessStatus::NeedsReceivingState) {
            return (string) $stats['total'];
        }

        $labelState = AtpLicenseRelevance::evaluationJurisdictionsLabel(2);

        if ($labelState === 'organization jurisdictions') {
            return (string) $stats['relevant_total'];
        }

        return $stats['relevant_total'].' · '.$labelState;
    }

    public static function badgeDescription(Site $site): ?string
    {
        $stats = self::summarize($site);

        if ($stats['status'] === SiteAtpReadinessStatus::FdaRegistered) {
            return 'FDA registered · all states';
        }

        if ($stats['status'] === SiteAtpReadinessStatus::NotMonitored) {
            return 'Manufacturer HQ · ATP expiry not monitored';
        }

        if ($stats['status'] !== SiteAtpReadinessStatus::NeedsReceivingState || $stats['expired_total'] === 0) {
            return null;
        }

        return $stats['expired_total'].' expired';
    }

    /**
     * @return Collection<int, AtpLicense>
     */
    public static function relevantLicenses(Site $site): Collection
    {
        $footprint = AtpLicenseRelevance::evaluationJurisdictionKeys();

        if ($footprint === []) {
            return collect();
        }

        return AtpLicenseRelevance::filterToFootprint(self::licenses($site), $footprint);
    }

    /**
     * @return Collection<int, AtpLicense>
     */
    public static function otherStateLicenses(Site $site): Collection
    {
        $footprint = AtpLicenseRelevance::evaluationJurisdictionKeys();
        $licenses = self::licenses($site);

        if ($footprint === []) {
            return $licenses->values();
        }

        return $licenses
            ->reject(fn (AtpLicense $license): bool => AtpLicenseRelevance::licenseMatchesFootprint($license, $footprint))
            ->values();
    }

    /**
     * @return Collection<int, AtpLicense>
     */
    private static function licenses(Site $site): Collection
    {
        if ($site->relationLoaded('atpLicenses')) {
            return $site->atpLicenses
                ->filter(fn (AtpLicense $license): bool => (bool) $license->is_active)
                ->values();
        }

        return $site->atpLicenses()->active()->get();
    }

    /**
     * @param  Builder<Site>  $query
     * @return Builder<Site>
     */
    public static function applyStatusFilter(Builder $query, SiteAtpReadinessStatus $status): Builder
    {
        if ($status === SiteAtpReadinessStatus::FdaRegistered) {
            $ids = array_values(array_unique(array_merge(
                ManufacturerDecrsAuthorization::siteIds($query),
                self::manufacturerHeadquartersWithFdaOrgIds($query),
            )));

            return $ids === []
                ? $query->whereRaw('0 = 1')
                : $query->whereIn('id', $ids);
        }

        if ($status === SiteAtpReadinessStatus::NotMonitored) {
            return $query
                ->where('is_headquarters', true)
                ->whereHas('tradingPartner', fn (Builder $partner): Builder => $partner
                    ->where('partner_type', PartnerType::Manufacturer->value))
                ->whereNotIn('id', ManufacturerDecrsAuthorization::siteIds($query) ?: [0])
                ->whereNotIn('id', self::manufacturerHeadquartersWithFdaOrgIds($query) ?: [0]);
        }

        $footprint = AtpLicenseRelevance::evaluationJurisdictionKeys();

        if ($status === SiteAtpReadinessStatus::NeedsReceivingState) {
            return $footprint === [] ? $query : $query->whereRaw('0 = 1');
        }

        if ($footprint === []) {
            return $query->whereRaw('0 = 1');
        }

        $hasRelevant = fn (Builder $licenseQuery): Builder => self::applyFootprintMatch($licenseQuery, $footprint);
        $hasExpired = fn (Builder $licenseQuery): Builder => AtpLicenseExpiry::expired(
            self::applyFootprintMatch($licenseQuery, $footprint),
        );
        $hasExpiring = fn (Builder $licenseQuery): Builder => AtpLicenseExpiry::expiringSoon(
            self::applyFootprintMatch($licenseQuery, $footprint),
        );
        $hasUnknownExpiry = fn (Builder $licenseQuery): Builder => AtpLicenseExpiry::unknownExpiry(
            self::applyFootprintMatch($licenseQuery, $footprint),
        );

        $deemedIds = $status === SiteAtpReadinessStatus::NoLicenses
            ? array_values(array_unique(array_merge(
                ManufacturerDecrsAuthorization::siteIds($query),
                self::manufacturerHeadquartersIds($query),
            )))
            : [];

        return match ($status) {
            SiteAtpReadinessStatus::NoLicenses => tap(
                $query->whereDoesntHave('atpLicenses', $hasRelevant),
                function (Builder $filtered) use ($deemedIds): void {
                    if ($deemedIds !== []) {
                        $filtered->whereNotIn('id', $deemedIds);
                    }
                },
            ),
            SiteAtpReadinessStatus::Expired => $query->whereHas('atpLicenses', $hasExpired),
            SiteAtpReadinessStatus::Expiring => $query
                ->whereHas('atpLicenses', $hasExpiring)
                ->whereDoesntHave('atpLicenses', $hasExpired),
            SiteAtpReadinessStatus::UnknownExpiry => $query
                ->whereHas('atpLicenses', $hasUnknownExpiry)
                ->whereDoesntHave('atpLicenses', $hasExpired)
                ->whereDoesntHave('atpLicenses', $hasExpiring),
            SiteAtpReadinessStatus::Ready => $query
                ->whereHas('atpLicenses', $hasRelevant)
                ->whereDoesntHave('atpLicenses', $hasExpired)
                ->whereDoesntHave('atpLicenses', $hasExpiring)
                ->whereDoesntHave('atpLicenses', $hasUnknownExpiry),
            SiteAtpReadinessStatus::NeedsReceivingState => $query->whereRaw('0 = 1'),
            SiteAtpReadinessStatus::FdaRegistered => $query->whereRaw('0 = 1'),
            SiteAtpReadinessStatus::NotMonitored => $query->whereRaw('0 = 1'),
        };
    }

    /**
     * Licenses in the tenant org footprint (active).
     *
     * @param  Builder<AtpLicense>  $query
     * @return Builder<AtpLicense>
     */
    public static function applyFootprintRelevantMatch(Builder $query): Builder
    {
        $footprint = AtpLicenseRelevance::evaluationJurisdictionKeys();

        if ($footprint === []) {
            return $query->whereRaw('0 = 1');
        }

        return self::applyFootprintMatch($query->where('is_active', true), $footprint);
    }

    /**
     * Active licenses outside the tenant evaluation jurisdictions.
     *
     * @param  Builder<AtpLicense>  $query
     * @return Builder<AtpLicense>
     */
    public static function applyOutsideFootprintMatch(Builder $query): Builder
    {
        $footprint = AtpLicenseRelevance::evaluationJurisdictionKeys();

        if ($footprint === []) {
            return $query->where('is_active', true);
        }

        return $query
            ->where('is_active', true)
            ->where(function (Builder $outer) use ($footprint): void {
                $outer->whereNot(function (Builder $notIn) use ($footprint): void {
                    self::applyFootprintMatch($notIn, $footprint);
                });
            });
    }

    /**
     * @deprecated Prefer applyFootprintRelevantMatch — single-state US-only helper.
     *
     * @param  Builder<AtpLicense>  $query
     * @return Builder<AtpLicense>
     */
    public static function applyStateMatch(Builder $query, string $tenantState): Builder
    {
        return $query->whereRaw('UPPER(TRIM(license_state)) = ?', [self::normalizeState($tenantState)]);
    }

    /**
     * @deprecated Prefer applyOutsideFootprintMatch.
     *
     * @param  Builder<AtpLicense>  $query
     * @return Builder<AtpLicense>
     */
    public static function applyOtherStateMatch(Builder $query, string $tenantState): Builder
    {
        return $query->whereRaw('UPPER(TRIM(license_state)) != ?', [self::normalizeState($tenantState)]);
    }

    /**
     * @param  Builder<AtpLicense>  $query
     * @param  list<string>  $footprintKeys
     * @return Builder<AtpLicense>
     */
    private static function applyFootprintMatch(Builder $query, array $footprintKeys): Builder
    {
        $query->where('is_active', true);

        $hasCountry = Schema::hasColumn('atp_licenses', 'license_country');

        return $query->where(function (Builder $outer) use ($footprintKeys, $hasCountry): void {
            foreach ($footprintKeys as $key) {
                [$country, $state] = array_pad(explode('|', $key, 2), 2, null);

                if (! is_string($country) || ! is_string($state) || $state === '') {
                    continue;
                }

                $outer->orWhere(function (Builder $inner) use ($country, $state, $hasCountry): void {
                    if ($hasCountry) {
                        $inner->whereRaw('UPPER(TRIM(COALESCE(license_country, ?))) = ?', ['US', $country]);
                    } elseif ($country !== 'US') {
                        $inner->whereRaw('0 = 1');

                        return;
                    }

                    $inner->whereRaw('UPPER(TRIM(license_state)) = ?', [$state]);
                });
            }
        });
    }

    /**
     * @param  Builder<Site>  $query
     * @return list<int>
     */
    private static function manufacturerHeadquartersIds(Builder $query): array
    {
        return (clone $query)
            ->where('is_headquarters', true)
            ->whereHas('tradingPartner', fn (Builder $partner): Builder => $partner
                ->where('partner_type', PartnerType::Manufacturer->value))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * Manufacturer HQ sites linked to an FDA organization (national authorization).
     *
     * @param  Builder<Site>  $query
     * @return list<int>
     */
    private static function manufacturerHeadquartersWithFdaOrgIds(Builder $query): array
    {
        return (clone $query)
            ->where('is_headquarters', true)
            ->whereHas('tradingPartner', fn (Builder $partner): Builder => $partner
                ->where('partner_type', PartnerType::Manufacturer->value)
                ->whereNotNull('fda_organization_id'))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    private static function normalizeState(string $state): string
    {
        return UsState::normalize($state) ?? strtoupper(trim($state));
    }

    private static function isExpired(AtpLicense $license, Carbon $today): bool
    {
        return $license->license_expiration_date !== null
            && $license->license_expiration_date->lt($today);
    }

    private static function isExpiringWithin90Days(
        AtpLicense $license,
        Carbon $today,
        Carbon $in90Days,
    ): bool {
        if ($license->license_expiration_date === null) {
            return false;
        }

        return $license->license_expiration_date->gte($today)
            && $license->license_expiration_date->lte($in90Days);
    }

    /**
     * @param  list<string>  $footprint
     */
    private static function resolveStatus(
        array $footprint,
        int $relevantTotal,
        int $relevantExpired,
        int $relevantExpiringWithin90Days,
        int $relevantUnknownExpiry,
    ): SiteAtpReadinessStatus {
        if ($footprint === []) {
            return SiteAtpReadinessStatus::NeedsReceivingState;
        }

        if ($relevantTotal === 0) {
            return SiteAtpReadinessStatus::NoLicenses;
        }

        if ($relevantExpired > 0) {
            return SiteAtpReadinessStatus::Expired;
        }

        if ($relevantExpiringWithin90Days > 0) {
            return SiteAtpReadinessStatus::Expiring;
        }

        if ($relevantUnknownExpiry > 0) {
            return SiteAtpReadinessStatus::UnknownExpiry;
        }

        return SiteAtpReadinessStatus::Ready;
    }
}
