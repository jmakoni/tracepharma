<?php

namespace App\Actions\MasterData;

use App\Enums\FacilityType;
use App\Models\AtpLicense;
use App\Models\Fda\FdaWddFacility;
use App\Models\Fda\FdaWddLicense;
use App\Models\Site;
use App\Support\Fda\FdaTenantLink;
use App\Support\MasterData\SiteAtpReadiness;
use App\Support\Places\UsState;

/**
 * Copy active, unexpired WDD licenses onto a tenant site linked to a WDD facility.
 *
 * Establishment-linked sites and organization docks are skipped.
 */
final class CopyFdaWddLicensesToTenantSite
{
    /**
     * @return int Licenses copied
     */
    public function handle(Site $tenantSite): int
    {
        return $this->sync($tenantSite)['copied'];
    }

    /**
     * @return array{copied: int, pruned: int, stamped: int, unmatched: int}
     */
    public function sync(Site $tenantSite): array
    {
        $tenantSite->loadMissing('tradingPartner');

        if ($this->isOrganizationFacility($tenantSite)) {
            return ['copied' => 0, 'pruned' => 0, 'stamped' => 0, 'unmatched' => 0];
        }

        $facilityId = FdaTenantLink::wddFacilityId($tenantSite);

        if ($facilityId === null) {
            return ['copied' => 0, 'pruned' => 0, 'stamped' => 0, 'unmatched' => 0];
        }

        if (blank($tenantSite->fda_wdd_facility_id)) {
            $facility = FdaWddFacility::query()->find($facilityId);
            if ($facility !== null) {
                FdaTenantLink::stampSiteFromWddFacility($tenantSite, $facility);
                $tenantSite->refresh();
            }
        }

        $copied = 0;
        $keys = [];

        FdaWddLicense::query()
            ->with('facility')
            ->where('fda_wdd_facility_id', $facilityId)
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->whereNull('expiration_date')
                    ->orWhereDate('expiration_date', '>=', now()->toDateString());
            })
            ->orderBy('id')
            ->each(function (FdaWddLicense $license) use ($tenantSite, &$copied, &$keys): void {
                $state = $this->normalizeState($license->jurisdiction);
                $number = (string) $license->license_number;

                $tenantLicense = AtpLicense::query()->updateOrCreate(
                    [
                        'site_id' => $tenantSite->getKey(),
                        'license_state' => $state,
                        'license_number' => $number,
                    ],
                    [
                        'fda_wdd_license_id' => $license->id,
                        'facility_type' => $license->facility?->facility_type ?? FacilityType::Wdd,
                        'license_expiration_date' => $license->expiration_date,
                        'reporting_year' => $license->reporting_year,
                        // Contacts are recorded per facility, not per license line.
                        'facility_contact_person' => $license->facility?->contact_person,
                        'facility_contact_email' => $license->facility?->contact_email,
                        'facility_contact_phone' => $license->facility?->contact_phone,
                        'is_active' => true,
                    ],
                );

                FdaTenantLink::stampLicense($tenantLicense, $license);

                $keys[] = $this->licenseKey($state, $number);
                $copied++;
            });

        $pruned = $this->deactivateMissing($tenantSite, $keys);

        $tenantSite->unsetRelation('atpLicenses');
        SiteAtpReadiness::forget($tenantSite);

        return [
            'copied' => $copied,
            'pruned' => $pruned,
            'stamped' => $copied,
            'unmatched' => 0,
        ];
    }

    /**
     * @param  list<string>  $keys
     */
    private function deactivateMissing(Site $tenantSite, array $keys): int
    {
        $stale = AtpLicense::query()
            ->where('site_id', $tenantSite->getKey())
            ->where('is_active', true)
            ->whereNotNull('fda_wdd_license_id')
            ->get()
            ->reject(fn (AtpLicense $license): bool => in_array(
                $this->licenseKey($license->license_state, $license->license_number),
                $keys,
                true,
            ));

        $stale->each(fn (AtpLicense $license) => $license->forceFill(['is_active' => false])->save());

        return $stale->count();
    }

    private function licenseKey(?string $state, ?string $number): string
    {
        return $this->normalizeState($state).'|'.strtoupper(trim((string) $number));
    }

    private function isOrganizationFacility(Site $tenantSite): bool
    {
        return $tenantSite->trading_partner_id === null
            || (bool) $tenantSite->is_organization_facility;
    }

    private function normalizeState(?string $state): string
    {
        return UsState::normalize($state) ?? strtoupper(trim((string) $state));
    }
}
