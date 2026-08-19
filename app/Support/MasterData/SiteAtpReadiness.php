<?php

namespace App\Support\MasterData;

use App\Enums\FacilityType;
use App\Enums\SiteAtpReadinessStatus;
use App\Models\AtpLicense;
use App\Models\Site;
use App\Support\Places\UsState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use WeakMap;

final class SiteAtpReadiness
{
    /**
     * Summaries already computed for a site instance.
     *
     * A table row asks for the badge label, its colour and its description, which is
     * three identical summaries — and three license queries when the relation is not
     * eager-loaded. Keyed by instance rather than by id so a re-render, which reads
     * fresh models, recomputes instead of showing a licence change that already
     * happened as if it had not.
     *
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

    /**
     * Drop memoized summaries. Callers that change licences on a site they keep
     * holding — and then read its readiness again — need the recomputation.
     */
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
        if (ManufacturerDecrsAuthorization::matches($site)) {
            return [
                'total' => 0,
                'expired_total' => 0,
                'relevant_total' => 0,
                'relevant_expired' => 0,
                'relevant_expiring_within_90_days' => 0,
                'relevant_unknown_expiry' => 0,
                'tenant_state' => TenantReceivingState::resolve(),
                'status' => SiteAtpReadinessStatus::FdaRegistered,
                'facility_types' => collect(),
            ];
        }

        $licenses = self::licenses($site);
        $tenantState = TenantReceivingState::resolve();

        $today = AtpLicenseExpiry::today();
        $in90Days = $today->copy()->addDays(AtpLicenseExpiry::EXPIRING_WINDOW_DAYS);

        $expiredTotal = $licenses
            ->filter(fn (AtpLicense $license): bool => self::isExpired($license, $today))
            ->count();

        $relevantLicenses = $tenantState !== null
            ? $licenses->filter(fn (AtpLicense $license): bool => self::licenseMatchesState($license, $tenantState))
            : collect();

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
            $tenantState,
            $relevantLicenses->count(),
            $relevantExpired,
            $relevantExpiringWithin90Days,
            $relevantUnknownExpiry,
        );

        $facilityTypesSource = $tenantState !== null ? $relevantLicenses : $licenses;

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

        if ($stats['tenant_state'] === null) {
            return (string) $stats['total'];
        }

        return $stats['relevant_total'].' · '.$stats['tenant_state'];
    }

    public static function badgeDescription(Site $site): ?string
    {
        $stats = self::summarize($site);

        if ($stats['status'] === SiteAtpReadinessStatus::FdaRegistered) {
            return 'FDA registered · all states';
        }

        if ($stats['tenant_state'] !== null || $stats['expired_total'] === 0) {
            return null;
        }

        return $stats['expired_total'].' expired';
    }

    /**
     * Licenses matching the tenant receiving state. Empty when receiving state is unset.
     *
     * @return Collection<int, AtpLicense>
     */
    public static function relevantLicenses(Site $site): Collection
    {
        $tenantState = TenantReceivingState::resolve();

        if ($tenantState === null) {
            return collect();
        }

        return self::licenses($site)
            ->filter(fn (AtpLicense $license): bool => self::licenseMatchesState($license, $tenantState))
            ->values();
    }

    /**
     * Licenses that do not match the tenant receiving state.
     * When receiving state is unset, returns all licenses.
     *
     * @return Collection<int, AtpLicense>
     */
    public static function otherStateLicenses(Site $site): Collection
    {
        $tenantState = TenantReceivingState::resolve();
        $licenses = self::licenses($site);

        if ($tenantState === null) {
            return $licenses->values();
        }

        return $licenses
            ->reject(fn (AtpLicense $license): bool => self::licenseMatchesState($license, $tenantState))
            ->values();
    }

    /**
     * Active licenses only: licenses deactivated by catalog sync no longer
     * authorize anything.
     *
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
            $ids = ManufacturerDecrsAuthorization::siteIds($query);

            return $ids === []
                ? $query->whereRaw('0 = 1')
                : $query->whereIn('id', $ids);
        }

        $tenantState = TenantReceivingState::resolve();

        if ($status === SiteAtpReadinessStatus::NeedsReceivingState) {
            return $tenantState === null ? $query : $query->whereRaw('0 = 1');
        }

        if ($tenantState === null) {
            return $query->whereRaw('0 = 1');
        }

        $hasRelevant = fn (Builder $licenseQuery): Builder => self::applyRelevantMatch($licenseQuery, $tenantState);
        $hasExpired = fn (Builder $licenseQuery): Builder => AtpLicenseExpiry::expired(
            self::applyRelevantMatch($licenseQuery, $tenantState),
        );
        $hasExpiring = fn (Builder $licenseQuery): Builder => AtpLicenseExpiry::expiringSoon(
            self::applyRelevantMatch($licenseQuery, $tenantState),
        );
        $hasUnknownExpiry = fn (Builder $licenseQuery): Builder => AtpLicenseExpiry::unknownExpiry(
            self::applyRelevantMatch($licenseQuery, $tenantState),
        );

        $deemedIds = $status === SiteAtpReadinessStatus::NoLicenses
            ? ManufacturerDecrsAuthorization::siteIds($query)
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
        };
    }

    /**
     * The single predicate for "this license is for the receiving state", so
     * table badges, tab filters, and readiness filters never disagree.
     *
     * @param  Builder<AtpLicense>  $query
     * @return Builder<AtpLicense>
     */
    public static function applyStateMatch(Builder $query, string $tenantState): Builder
    {
        return $query->whereRaw('UPPER(TRIM(license_state)) = ?', [self::normalizeState($tenantState)]);
    }

    /**
     * @param  Builder<AtpLicense>  $query
     * @return Builder<AtpLicense>
     */
    public static function applyOtherStateMatch(Builder $query, string $tenantState): Builder
    {
        return $query->whereRaw('UPPER(TRIM(license_state)) != ?', [self::normalizeState($tenantState)]);
    }

    /**
     * Licenses that count towards readiness: in effect, for the receiving state.
     *
     * @param  Builder<AtpLicense>  $query
     * @return Builder<AtpLicense>
     */
    private static function applyRelevantMatch(Builder $query, string $tenantState): Builder
    {
        return self::applyStateMatch($query->where('is_active', true), $tenantState);
    }

    private static function licenseMatchesState(AtpLicense $license, string $tenantState): bool
    {
        $licenseState = self::normalizeLicenseState($license->license_state);

        return $licenseState !== null && $licenseState === self::normalizeState($tenantState);
    }

    private static function normalizeState(string $state): string
    {
        return UsState::normalize($state) ?? strtoupper(trim($state));
    }

    private static function normalizeLicenseState(?string $state): ?string
    {
        if (blank($state)) {
            return null;
        }

        return self::normalizeState((string) $state) ?: null;
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

    private static function resolveStatus(
        ?string $tenantState,
        int $relevantTotal,
        int $relevantExpired,
        int $relevantExpiringWithin90Days,
        int $relevantUnknownExpiry,
    ): SiteAtpReadinessStatus {
        if ($tenantState === null) {
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

        // No expiration date means the license cannot be shown to be in force.
        if ($relevantUnknownExpiry > 0) {
            return SiteAtpReadinessStatus::UnknownExpiry;
        }

        return SiteAtpReadinessStatus::Ready;
    }
}
