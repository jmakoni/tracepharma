<?php

declare(strict_types=1);

namespace App\Support\MasterData;

use App\Enums\PartnerType;
use App\Models\AtpLicense;
use App\Models\Site;
use App\Support\Places\UsState;
use Illuminate\Support\Collection;

/**
 * Shared ATP relevance: tenant org footprint jurisdictions and which partner
 * sites are in scope for expiry digests vs readiness badges.
 *
 * Footprint keys are "{COUNTRY}|{STATE}" (e.g. "US|IL", "CA|ON").
 */
final class AtpLicenseRelevance
{
    /**
     * Organization facility jurisdictions only (digest scoping).
     *
     * @return list<string>
     */
    public static function tenantFootprintKeys(): array
    {
        return Site::query()
            ->ownedByOrganization()
            ->whereNotNull('state')
            ->get(['state', 'country_code'])
            ->map(function (Site $site): ?string {
                return self::jurisdictionKey(
                    self::normalizeCountry($site->country_code),
                    $site->state,
                );
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Jurisdictions used for readiness badges, filters, outbound/soft-warn gates, and metrics.
     *
     * Prefers the org facility footprint; when empty, falls back to the preferred
     * receiving state as a single US jurisdiction so gates still evaluate licenses.
     *
     * @return list<string>
     */
    public static function evaluationJurisdictionKeys(): array
    {
        $footprint = self::tenantFootprintKeys();

        if ($footprint !== []) {
            return $footprint;
        }

        $state = TenantReceivingState::resolve();

        if ($state === null) {
            return [];
        }

        $key = self::jurisdictionKey('US', $state);

        return $key !== null ? [$key] : [];
    }

    /**
     * Human-readable EVAL jurisdictions for badges, metrics, and gate messages.
     *
     * When a preferred receiving state is set, it is the short badge/message label
     * even if the org footprint is wider (evaluation still uses full EVAL keys).
     * When unset, lists footprint jurisdictions (truncated).
     */
    public static function evaluationJurisdictionsLabel(int $max = 4): string
    {
        $keys = self::evaluationJurisdictionKeys();

        if ($keys === []) {
            return 'organization jurisdictions';
        }

        $preferred = TenantReceivingState::resolve();

        if ($preferred !== null) {
            return $preferred;
        }

        $parts = collect($keys)
            ->map(function (string $key): string {
                [$country, $state] = array_pad(explode('|', $key, 2), 2, '');

                return $country === 'US' ? $state : trim($country.' '.$state);
            })
            ->filter()
            ->values();

        if ($parts->isEmpty()) {
            return 'organization jurisdictions';
        }

        if ($parts->count() <= $max) {
            return $parts->implode(', ');
        }

        $shown = $parts->take($max)->implode(', ');

        return $shown.' +'.($parts->count() - $max).' more';
    }

    /**
     * Distinct US state codes from the footprint (compat for callers that only need US).
     *
     * @return list<string>
     */
    public static function tenantFootprintUsStates(): array
    {
        return collect(self::tenantFootprintKeys())
            ->filter(fn (string $key): bool => str_starts_with($key, 'US|'))
            ->map(fn (string $key): string => substr($key, 3))
            ->values()
            ->all();
    }

    public static function licenseMatchesFootprint(AtpLicense $license, ?array $footprintKeys = null): bool
    {
        $footprintKeys ??= self::tenantFootprintKeys();

        if ($footprintKeys === []) {
            return false;
        }

        $key = self::licenseJurisdictionKey($license);

        return $key !== null && in_array($key, $footprintKeys, true);
    }

    public static function licenseJurisdictionKey(AtpLicense $license): ?string
    {
        $country = self::normalizeCountry(
            $license->getAttribute('license_country') ?? 'US',
        );

        return self::jurisdictionKey($country, $license->license_state);
    }

    public static function isManufacturerHeadquarters(?Site $site): bool
    {
        if ($site === null || ! (bool) $site->is_headquarters) {
            return false;
        }

        $partner = $site->relationLoaded('tradingPartner')
            ? $site->tradingPartner
            : $site->tradingPartner()->first();

        return $partner !== null && $partner->partner_type === PartnerType::Manufacturer;
    }

    /**
     * Whether a site's licenses may appear on the owner expiry digest.
     * Manufacturer DCs require prior inbound ship-from evidence.
     */
    public static function siteEligibleForExpiryDigest(?Site $site, bool $requireReceiveProofForManufacturerDc = true): bool
    {
        if ($site === null) {
            return false;
        }

        if ($site->trading_partner_id === null) {
            return true;
        }

        $partner = $site->relationLoaded('tradingPartner')
            ? $site->tradingPartner
            : $site->tradingPartner()->first();

        if ($partner === null || $partner->partner_type !== PartnerType::Manufacturer) {
            return true;
        }

        if ((bool) $site->is_headquarters) {
            return false;
        }

        if (ManufacturerDecrsAuthorization::matches($site)) {
            return false;
        }

        if (! $requireReceiveProofForManufacturerDc) {
            return true;
        }

        return PartnerSiteHasInboundShipFrom::exists((int) $site->getKey());
    }

    /**
     * Partner sites counted in Compliance Alert Center ATP signals.
     *
     * Manufacturer HQ and FDA-registered plants are excluded. Other manufacturer
     * sites enter scope only after inbound ship-from evidence exists.
     */
    public static function siteInComplianceAlertScope(Site $site): bool
    {
        if ($site->trading_partner_id === null) {
            return false;
        }

        if (ManufacturerDecrsAuthorization::matches($site)) {
            return false;
        }

        if (self::isManufacturerHeadquarters($site)) {
            return false;
        }

        $partner = $site->relationLoaded('tradingPartner')
            ? $site->tradingPartner
            : $site->tradingPartner()->first();

        if ($partner !== null && $partner->partner_type === PartnerType::Manufacturer) {
            return PartnerSiteHasInboundShipFrom::exists((int) $site->getKey());
        }

        return true;
    }

    /**
     * @param  Collection<int, AtpLicense>  $licenses
     * @param  list<string>|null  $footprintKeys
     * @return Collection<int, AtpLicense>
     */
    public static function filterToFootprint(Collection $licenses, ?array $footprintKeys = null): Collection
    {
        $footprintKeys ??= self::tenantFootprintKeys();

        if ($footprintKeys === []) {
            return collect();
        }

        return $licenses
            ->filter(fn (AtpLicense $license): bool => self::licenseMatchesFootprint($license, $footprintKeys))
            ->values();
    }

    public static function jurisdictionKey(string $country, ?string $state): ?string
    {
        $stateNorm = self::normalizeSubdivision($country, $state);

        if ($stateNorm === null) {
            return null;
        }

        return $country.'|'.$stateNorm;
    }

    public static function normalizeCountry(?string $country): string
    {
        $value = strtoupper(trim((string) $country));

        return match ($value) {
            '', 'USA', 'UNITED STATES', 'US' => 'US',
            default => strlen($value) === 2 ? $value : 'US',
        };
    }

    public static function normalizeSubdivision(string $country, ?string $state): ?string
    {
        if ($country === 'US') {
            return UsState::normalize($state) ?? (filled($state) ? strtoupper(trim((string) $state)) : null);
        }

        $trimmed = strtoupper(trim((string) $state));

        return $trimmed !== '' ? $trimmed : null;
    }
}
