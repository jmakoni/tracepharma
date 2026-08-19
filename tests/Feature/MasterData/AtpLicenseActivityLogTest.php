<?php

namespace Tests\Feature\MasterData;

use App\Actions\MasterData\CopyFdaWddLicensesToTenantSite;
use App\Enums\FacilityType;
use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Models\AtpLicense;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaWddFacility;
use App\Models\Fda\FdaWddLicense;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Support\Fda\AddressFingerprint;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * An ATP licence is the evidence that a delivery to an address is lawful: it stops an
 * outbound send and warns on ingest. "Why was this shipment allowed?" is answered by the
 * licence as it stood at the time, so changes to it — above all a deactivation by FDA
 * sync — have to leave a trail.
 */
class AtpLicenseActivityLogTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $orgIds = [];

    /** @var list<int> */
    private array $tenantPartnerIds = [];

    /** @var list<int> */
    private array $tenantSiteIds = [];

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    #[Test]
    public function licence_number_state_expiry_and_activation_changes_are_logged(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $site = $this->tenantSite('SSOR CUT2 Licence Audit Dock');

            $licence = AtpLicense::query()->create([
                'site_id' => $site->getKey(),
                'facility_type' => FacilityType::Wdd,
                'license_number' => 'SSOR-CUT2-AUDIT-001',
                'license_state' => 'TX',
                'license_expiration_date' => now()->addYear()->toDateString(),
                'reporting_year' => (int) now()->year,
                'is_active' => true,
            ]);

            $this->assertNotNull($this->latestActivityFor($licence), 'Creating a licence must be logged.');

            $licence->update([
                'license_number' => 'SSOR-CUT2-AUDIT-002',
                'license_state' => 'IL',
                'is_active' => false,
            ]);

            $changes = $this->latestChangesFor($licence);

            $this->assertSame('SSOR-CUT2-AUDIT-002', $changes['attributes']['license_number'] ?? null);
            $this->assertSame('IL', $changes['attributes']['license_state'] ?? null);
            $this->assertFalse((bool) ($changes['attributes']['is_active'] ?? true));
            $this->assertSame('SSOR-CUT2-AUDIT-001', $changes['old']['license_number'] ?? null);
            $this->assertTrue((bool) ($changes['old']['is_active'] ?? false));
        } finally {
            $this->cleanup();
        }
    }

    /**
     * FDA sync deactivates a licence the FDA report has dropped. That is the change an
     * inspector is most likely to ask about, so it must not slip past as a bulk update.
     */
    #[Test]
    public function a_licence_deactivated_by_fda_sync_is_logged(): void
    {
        $org = FdaOrganization::query()->create([
            'original_name' => 'SSOR CUT2 Licence Audit Org',
            'canonical_name' => 'SSOR CUT2 LICENCE AUDIT ORG',
            'name' => 'SSOR CUT2 Licence Audit Org',
            'partner_type' => PartnerType::Wholesaler,
            'is_active' => true,
        ]);
        $this->orgIds[] = (int) $org->getKey();

        $facility = FdaWddFacility::query()->create([
            'fda_organization_id' => $org->id,
            'facility_type' => FacilityType::Wdd,
            'name' => 'SSOR CUT2 Audit DC',
            'facility_name' => 'SSOR CUT2 Audit DC',
            'address_fingerprint' => AddressFingerprint::make('1 Audit St', 'Dallas', 'TX', '75201', 'US'),
            'is_headquarters' => true,
            'is_active' => true,
        ]);

        $wddLicense = FdaWddLicense::query()->create([
            'fda_wdd_facility_id' => $facility->id,
            'license_number' => 'SSOR-CUT2-AUDIT-FDA-001',
            'jurisdiction' => 'TX',
            'expiration_date' => now()->addYear(),
            'reporting_year' => (int) now()->year,
            'is_active' => true,
        ]);

        $this->initializeDemo2Tenant();

        try {
            $site = $this->tenantSite('SSOR CUT2 Licence Audit Synced Dock', $org, $facility);

            app(CopyFdaWddLicensesToTenantSite::class)->sync($site);

            $licence = AtpLicense::query()
                ->where('site_id', $site->getKey())
                ->where('license_number', 'SSOR-CUT2-AUDIT-FDA-001')
                ->sole();

            $wddLicense->delete();

            $counts = app(CopyFdaWddLicensesToTenantSite::class)->sync($site->fresh());

            $this->assertSame(1, $counts['pruned']);
            $this->assertFalse((bool) $licence->fresh()->is_active);

            $changes = $this->latestChangesFor($licence);

            $this->assertFalse((bool) ($changes['attributes']['is_active'] ?? true));
            $this->assertTrue((bool) ($changes['old']['is_active'] ?? false));
        } finally {
            $this->cleanup();
        }
    }

    private function tenantSite(
        string $name,
        ?FdaOrganization $organization = null,
        ?FdaWddFacility $facility = null,
    ): Site {
        $partner = TradingPartner::query()->create([
            'fda_organization_id' => $organization?->getKey(),
            'name' => $name.' Partner '.uniqid(),
            'gln' => fake()->unique()->numerify('#############'),
            'partner_type' => PartnerType::Wholesaler,
            'country_code' => 'US',
            'is_active' => true,
        ]);
        $this->tenantPartnerIds[] = (int) $partner->getKey();

        $site = Site::query()->create([
            'fda_wdd_facility_id' => $facility?->getKey(),
            'trading_partner_id' => $partner->getKey(),
            'name' => $name,
            'is_headquarters' => true,
            'country_code' => 'US',
            'is_active' => true,
            'is_organization_facility' => false,
        ]);
        $this->tenantSiteIds[] = (int) $site->getKey();

        return $site;
    }

    private function latestActivityFor(AtpLicense $licence): ?Activity
    {
        return Activity::query()
            ->where('subject_type', $licence->getMorphClass())
            ->where('subject_id', $licence->getKey())
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array{attributes?: array<string, mixed>, old?: array<string, mixed>}
     */
    private function latestChangesFor(AtpLicense $licence): array
    {
        return $this->latestActivityFor($licence)?->attribute_changes?->toArray() ?? [];
    }

    private function initializeDemo2Tenant(): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo Pharmacy',
                'profile' => TenantProfile::Pharmacy,
                'status' => 'active',
                'tenancy_db_name' => self::DEMO2_DATABASE,
            ]));

            $tenant->domains()->create(['domain' => self::DEMO2_DOMAIN]);
        } else {
            $tenant->domains()->firstOrCreate(['domain' => self::DEMO2_DOMAIN]);
        }

        if (! self::$demo2TenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();

            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant);

        return $tenant;
    }

    private function cleanup(): void
    {
        if (tenancy()->initialized) {
            if ($this->tenantSiteIds !== []) {
                $licenceIds = AtpLicense::query()
                    ->whereIn('site_id', $this->tenantSiteIds)
                    ->pluck('id')
                    ->all();

                if ($licenceIds !== []) {
                    Activity::query()
                        ->where('subject_type', (new AtpLicense)->getMorphClass())
                        ->whereIn('subject_id', $licenceIds)
                        ->delete();

                    AtpLicense::query()->whereIn('id', $licenceIds)->delete();
                }

                Site::query()->whereIn('id', $this->tenantSiteIds)->delete();
            }

            if ($this->tenantPartnerIds !== []) {
                TradingPartner::query()->whereIn('id', $this->tenantPartnerIds)->delete();
            }

            tenancy()->end();
        }

        if ($this->orgIds !== []) {
            FdaWddLicense::query()->whereIn('fda_wdd_facility_id', function ($query): void {
                $query->select('id')->from('fda_wdd_facilities')->whereIn('fda_organization_id', $this->orgIds);
            })->delete();
            FdaWddFacility::query()->whereIn('fda_organization_id', $this->orgIds)->delete();
            FdaOrganization::query()->whereIn('id', $this->orgIds)->delete();
        }

        $this->orgIds = [];
        $this->tenantPartnerIds = [];
        $this->tenantSiteIds = [];
    }
}
