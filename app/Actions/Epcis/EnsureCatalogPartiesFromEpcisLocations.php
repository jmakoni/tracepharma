<?php

namespace App\Actions\Epcis;

use App\Actions\MasterData\CopyFdaWddLicensesToTenantSite;
use App\Actions\MasterData\CreateHqSiteForTradingPartner;
use App\Actions\MasterData\EnsureOrganizationPartnerFromFda;
use App\Enums\FacilityType;
use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Models\Fda\FdaEstablishment;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaWddFacility;
use App\Models\Fda\FdaWddLicense;
use App\Models\Site;
use App\Models\TradingPartner;
use App\Support\Custody\TenantGlnSet;
use App\Support\Fda\FdaPrefill;
use App\Support\Fda\FdaTenantLink;
use App\Support\TenantFeatures;

/**
 * Ensure tenant trading partners/sites from EPCIS Location vocabulary.
 *
 * FDA registry rows (organization / establishment / WDD facility) are the
 * shared identity. Unknown GLNs stay tenant-local. Catalog rows are not created.
 */
final class EnsureCatalogPartiesFromEpcisLocations
{
    private ?TenantGlnSet $tenantGlns = null;

    /**
     * @param  list<array{
     *     gln?: string|null,
     *     gln_uri?: string|null,
     *     name?: string|null,
     *     street_address?: string|null,
     *     city?: string|null,
     *     state?: string|null,
     *     postal_code?: string|null,
     *     country_code?: string|null
     * }>  $locations
     * @param  array{
     *     source_owning_party_gln?: ?string,
     *     source_location_gln?: ?string,
     *     destination_owning_party_gln?: ?string,
     *     destination_location_gln?: ?string,
     * }  $partyGlns
     * @param  array{
     *     product_manufacturer_name?: ?string,
     *     product_manufacturer_gln?: ?string,
     * }  $roleContext
     * @return array{
     *     partners_created: int,
     *     partners_filled: int,
     *     sites_created: int,
     *     sites_filled: int,
     *     tenant_partners_ensured: int,
     *     tenant_sites_created: int,
     *     tenant_sites_filled: int,
     * }
     */
    public function handle(array $locations, array $partyGlns, array $roleContext = []): array
    {
        $this->tenantGlns = new TenantGlnSet;

        $stats = [
            'partners_created' => 0,
            'partners_filled' => 0,
            'sites_created' => 0,
            'sites_filled' => 0,
            'tenant_partners_ensured' => 0,
            'tenant_sites_created' => 0,
            'tenant_sites_filled' => 0,
        ];

        /** @var array<string, array<string, mixed>> $byGln */
        $byGln = [];
        foreach ($locations as $location) {
            $gln = $this->normalizeGln($location['gln'] ?? null);
            $name = filled($location['name'] ?? null) ? trim((string) $location['name']) : null;
            if ($gln === null || $name === null) {
                continue;
            }

            $byGln[$gln] = array_merge($location, [
                'gln' => $gln,
                'name' => $name,
            ]);
        }

        $sourceOwning = $this->normalizeGln($partyGlns['source_owning_party_gln'] ?? null);
        $destOwning = $this->normalizeGln($partyGlns['destination_owning_party_gln'] ?? null);
        $sourceLocation = $this->normalizeGln($partyGlns['source_location_gln'] ?? null);
        $destLocation = $this->normalizeGln($partyGlns['destination_location_gln'] ?? null);

        [$sourceDefaultRole, $destDefaultRole] = $this->profileDefaultPartyRoles();

        if ($sourceOwning !== null) {
            $this->ensureOwningPartner(
                $sourceOwning,
                $sourceDefaultRole,
                $byGln,
                $stats,
                $roleContext,
                'source',
            );
        }

        if ($destOwning !== null) {
            $this->ensureOwningPartner(
                $destOwning,
                $destDefaultRole,
                $byGln,
                $stats,
                $roleContext,
                'destination',
            );
        }

        if ($sourceLocation !== null && $sourceOwning !== null) {
            $this->ensureLocationSite($sourceLocation, $sourceOwning, $byGln, $stats);
        }

        if ($destLocation !== null && $destOwning !== null) {
            $this->ensureLocationSite($destLocation, $destOwning, $byGln, $stats);
        }

        return $stats;
    }

    /**
     * @param  array<string, array<string, mixed>>  $byGln
     * @param  array<string, int>  $stats
     * @param  array{
     *     product_manufacturer_name?: ?string,
     *     product_manufacturer_gln?: ?string,
     * }  $roleContext
     */
    private function ensureOwningPartner(
        string $gln,
        PartnerType $profileDefaultRole,
        array $byGln,
        array &$stats,
        array $roleContext = [],
        string $side = 'source',
    ): void {
        $location = $byGln[$gln] ?? null;
        if ($location === null || blank($location['name'] ?? null)) {
            return;
        }

        if ($this->isTenantGln($gln)) {
            return;
        }

        $organization = FdaOrganization::query()->where('gln', $gln)->first();
        $partnerType = $this->resolveOwningPartnerRole(
            $gln,
            $side,
            $byGln,
            $profileDefaultRole,
            $roleContext,
            $organization,
        );

        $existing = TradingPartner::query()->where('gln', $gln)->first()
            ?? ($organization !== null
                ? TradingPartner::query()->where('fda_organization_id', $organization->getKey())->first()
                : null);

        if ($organization !== null) {
            $created = $existing === null;
            $partner = app(EnsureOrganizationPartnerFromFda::class)->handle($organization, $partnerType);

            if ($partner === null) {
                return;
            }

            $fills = $this->blankAddressFills($partner, $location);
            if ($fills !== []) {
                $partner->forceFill($fills)->save();
                $stats['partners_filled']++;
            }

            if ($created) {
                $stats['partners_created']++;
            }

            $stats['tenant_partners_ensured']++;

            return;
        }

        if ($existing !== null) {
            $fills = $this->blankAddressFills($existing, $location);
            if ($existing->partner_type === null || $existing->partner_type === PartnerType::Other) {
                $fills['partner_type'] = $partnerType;
            }

            if ($fills !== []) {
                $existing->forceFill($fills)->save();
                $stats['partners_filled']++;
            }

            app(CreateHqSiteForTradingPartner::class)->handle($existing);
            $stats['tenant_partners_ensured']++;

            return;
        }

        TradingPartner::query()->create([
            'name' => (string) $location['name'],
            'gln' => $gln,
            'partner_type' => $partnerType,
            'street_address' => $location['street_address'] ?? null,
            'city' => $location['city'] ?? null,
            'state' => $location['state'] ?? null,
            'zipcode' => $location['postal_code'] ?? null,
            'country_code' => $this->normalizeCountry($location['country_code'] ?? null),
            'is_active' => true,
        ]);

        $partner = TradingPartner::query()->where('gln', $gln)->first();
        if ($partner !== null) {
            app(CreateHqSiteForTradingPartner::class)->handle($partner);
        }

        $stats['partners_created']++;
        $stats['tenant_partners_ensured']++;
    }

    /**
     * @return array{0: PartnerType, 1: PartnerType}
     */
    private function profileDefaultPartyRoles(): array
    {
        $profile = TenantFeatures::forTenant(tenant())->profile();

        return match ($profile) {
            TenantProfile::Pharmacy => [PartnerType::Wholesaler, PartnerType::Pharmacy],
            TenantProfile::Manufacturer,
            TenantProfile::DrugWholesaler,
            TenantProfile::Prepackager => [PartnerType::Wholesaler, PartnerType::Wholesaler],
            TenantProfile::Logistics3pl => [PartnerType::Wholesaler, PartnerType::Logistics3pl],
            TenantProfile::DentalMedicalSupply => [PartnerType::Wholesaler, PartnerType::Other],
            default => [PartnerType::Other, PartnerType::Other],
        };
    }

    /**
     * @param  array<string, array<string, mixed>>  $byGln
     * @param  array{
     *     product_manufacturer_name?: ?string,
     *     product_manufacturer_gln?: ?string,
     * }  $roleContext
     */
    private function resolveOwningPartnerRole(
        string $gln,
        string $side,
        array $byGln,
        PartnerType $profileDefaultRole,
        array $roleContext,
        ?FdaOrganization $organization = null,
    ): PartnerType {
        if ($organization?->partner_type !== null) {
            return $organization->partner_type;
        }

        $existingTenant = TradingPartner::query()->where('gln', $gln)->first();
        if ($existingTenant?->partner_type !== null) {
            return $existingTenant->partner_type;
        }

        $fromAtp = $this->partnerTypeFromWddLicenses($gln);
        if ($fromAtp !== null) {
            return $fromAtp;
        }

        if (
            $side === 'source'
            && $this->profileInfersManufacturerFromProduct()
            && $this->sourceMatchesProductManufacturer($gln, $byGln, $roleContext)
        ) {
            return PartnerType::Manufacturer;
        }

        return $profileDefaultRole;
    }

    private function profileInfersManufacturerFromProduct(): bool
    {
        return match (TenantFeatures::forTenant(tenant())->profile()) {
            TenantProfile::Manufacturer,
            TenantProfile::DrugWholesaler,
            TenantProfile::Prepackager => true,
            default => false,
        };
    }

    /**
     * @param  array<string, array<string, mixed>>  $byGln
     * @param  array{
     *     product_manufacturer_name?: ?string,
     *     product_manufacturer_gln?: ?string,
     * }  $roleContext
     */
    private function sourceMatchesProductManufacturer(string $gln, array $byGln, array $roleContext): bool
    {
        $productManufacturerGln = $this->normalizeGln($roleContext['product_manufacturer_gln'] ?? null);
        if ($productManufacturerGln !== null && $productManufacturerGln === $gln) {
            return true;
        }

        $productManufacturerName = filled($roleContext['product_manufacturer_name'] ?? null)
            ? trim((string) $roleContext['product_manufacturer_name'])
            : null;
        if ($productManufacturerName === null) {
            return false;
        }

        $sourceName = filled($byGln[$gln]['name'] ?? null)
            ? trim((string) $byGln[$gln]['name'])
            : null;

        return $sourceName !== null
            && $this->normalizePartyName($sourceName) === $this->normalizePartyName($productManufacturerName);
    }

    private function partnerTypeFromWddLicenses(string $gln): ?PartnerType
    {
        $facilityIds = FdaWddFacility::query()
            ->where('gln', $gln)
            ->pluck('id');

        if ($facilityIds->isEmpty()) {
            $organization = FdaOrganization::query()->where('gln', $gln)->first();
            if ($organization === null) {
                return null;
            }

            $facilityIds = FdaWddFacility::query()
                ->where('fda_organization_id', $organization->getKey())
                ->pluck('id');
        }

        if ($facilityIds->isEmpty()) {
            return null;
        }

        $facilityTypes = FdaWddLicense::query()
            ->whereIn('fda_wdd_facility_id', $facilityIds)
            ->where('is_active', true)
            ->get()
            ->map(fn (FdaWddLicense $license): ?FacilityType => $license->facility?->facility_type)
            ->filter()
            ->unique();

        $directTypes = FdaWddFacility::query()
            ->whereIn('id', $facilityIds)
            ->pluck('facility_type')
            ->filter();

        $types = $facilityTypes->merge($directTypes);

        if ($types->contains(FacilityType::Wdd)) {
            return PartnerType::Wholesaler;
        }

        if ($types->contains(FacilityType::ThreePl)) {
            return PartnerType::Logistics3pl;
        }

        return null;
    }

    private function normalizePartyName(?string $name): string
    {
        if (! filled($name)) {
            return '';
        }

        return strtolower(preg_replace('/\s+/', ' ', trim($name)) ?? '');
    }

    /**
     * @param  array<string, array<string, mixed>>  $byGln
     * @param  array<string, int>  $stats
     */
    private function ensureLocationSite(
        string $siteGln,
        string $owningPartyGln,
        array $byGln,
        array &$stats,
    ): void {
        $location = $byGln[$siteGln] ?? null;
        if ($location === null || blank($location['name'] ?? null)) {
            return;
        }

        if ($this->isTenantGln($siteGln) || $this->isTenantGln($owningPartyGln)) {
            return;
        }

        $tenantPartner = TradingPartner::query()->where('gln', $owningPartyGln)->first();
        if ($tenantPartner === null) {
            $organization = FdaOrganization::query()->where('gln', $owningPartyGln)->first();
            if ($organization !== null) {
                $tenantPartner = TradingPartner::query()
                    ->where('fda_organization_id', $organization->getKey())
                    ->first();
            }
        }

        if ($tenantPartner === null) {
            return;
        }

        $establishment = FdaEstablishment::query()->where('gln', $siteGln)->first();
        $facility = FdaWddFacility::query()->where('gln', $siteGln)->first();
        if ($tenantPartner->partner_type === PartnerType::Manufacturer) {
            $facility = null;
        }
        $isHeadquarters = $siteGln === $owningPartyGln;

        $existingSite = Site::query()->where('gln', $siteGln)->first();

        if ($existingSite !== null) {
            $fills = $this->blankAddressFills($existingSite, $location);
            if ($existingSite->trading_partner_id === null && ! $this->isOrganizationOwnedSite($existingSite, $siteGln)) {
                $fills['trading_partner_id'] = $tenantPartner->getKey();
            }
            if ($fills !== []) {
                $existingSite->forceFill($fills)->save();
                $stats['sites_filled']++;
                $stats['tenant_sites_filled']++;
            }

            $this->recordPublishedSgln($siteGln, $location);
            $this->stampSite($existingSite->fresh() ?? $existingSite, $establishment, $facility);

            return;
        }

        $attributes = [
            'trading_partner_id' => $tenantPartner->getKey(),
            'name' => (string) $location['name'],
            'gln' => $siteGln,
            'is_headquarters' => $isHeadquarters,
            'street_address' => $location['street_address'] ?? null,
            'city' => $location['city'] ?? null,
            'state' => $location['state'] ?? null,
            'zipcode' => $location['postal_code'] ?? null,
            'country_code' => $this->normalizeCountry($location['country_code'] ?? null),
            'is_active' => true,
        ];

        if ($establishment !== null && $facility === null) {
            $attributes = array_merge(FdaPrefill::establishmentAttributes($establishment), $attributes);
        } elseif ($facility !== null && $establishment === null) {
            $attributes = array_merge(FdaPrefill::wddFacilityAttributes($facility), $attributes);
        } elseif ($facility !== null && $tenantPartner->partner_type !== PartnerType::Manufacturer) {
            $attributes = array_merge(FdaPrefill::wddFacilityAttributes($facility), $attributes);
            $establishment = null;
        } elseif ($establishment !== null) {
            $attributes = array_merge(FdaPrefill::establishmentAttributes($establishment), $attributes);
            $facility = null;
        }

        $site = Site::query()->create($attributes);
        $this->recordPublishedSgln($siteGln, $location);
        $this->stampSite($site, $establishment, $facility);

        if ($facility !== null) {
            app(CopyFdaWddLicensesToTenantSite::class)->handle($site);
        }

        $stats['sites_created']++;
        $stats['tenant_sites_created']++;
    }

    private function stampSite(Site $site, ?FdaEstablishment $establishment, ?FdaWddFacility $facility): void
    {
        if ($establishment !== null && $facility === null) {
            FdaTenantLink::stampSiteFromEstablishment($site, $establishment);

            return;
        }

        if ($facility !== null && $establishment === null) {
            FdaTenantLink::stampSiteFromWddFacility($site, $facility);

            return;
        }

        if ($facility !== null && $site->tradingPartner?->partner_type !== PartnerType::Manufacturer) {
            FdaTenantLink::stampSiteFromWddFacility($site, $facility);

            return;
        }

        if ($establishment !== null) {
            FdaTenantLink::stampSiteFromEstablishment($site, $establishment);
        }
    }

    private function isTenantGln(?string $gln): bool
    {
        return ($this->tenantGlns ??= new TenantGlnSet)->contains($gln);
    }

    private function isOrganizationOwnedSite(Site $site, string $siteGln): bool
    {
        return $site->trading_partner_id === null
            || (bool) $site->is_organization_facility
            || $this->isTenantGln($siteGln);
    }

    /**
     * @param  array<string, mixed>  $location
     * @return array<string, mixed>
     */
    private function blankAddressFills(TradingPartner|Site $model, array $location): array
    {
        $map = [
            'street_address' => $location['street_address'] ?? null,
            'city' => $location['city'] ?? null,
            'state' => $location['state'] ?? null,
            'zipcode' => $location['postal_code'] ?? null,
            'country_code' => $this->normalizeCountry($location['country_code'] ?? null),
        ];

        $fills = [];
        foreach ($map as $field => $value) {
            if (blank($model->{$field}) && filled($value)) {
                $fills[$field] = $value;
            }
        }

        return $fills;
    }

    private function normalizeCountry(mixed $country): string
    {
        if ($country === null || trim((string) $country) === '') {
            return 'US';
        }

        return strtoupper(trim((string) $country));
    }

    private function normalizeGln(mixed $gln): ?string
    {
        if ($gln === null) {
            return null;
        }

        $normalized = preg_replace('/\D+/', '', (string) $gln) ?? '';

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @param  array<string, mixed>  $location
     */
    private function recordPublishedSgln(string $siteGln, array $location): void
    {
        $urn = $location['gln_uri'] ?? null;
        if (! is_string($urn) || $urn === '') {
            return;
        }

        app(RecordPublishedSglnOnPartner::class)->handle($siteGln, $urn);
    }
}
