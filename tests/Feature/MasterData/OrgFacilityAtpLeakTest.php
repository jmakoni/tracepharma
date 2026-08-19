<?php

namespace Tests\Feature\MasterData;

use App\Actions\Epcis\EnsureCatalogPartiesFromEpcisLocations;
use App\Enums\FacilityType;
use App\Enums\PartnerType;
use App\Enums\SiteAtpReadinessStatus;
use App\Enums\TenantProfile;
use App\Models\AtpLicense;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaWddFacility;
use App\Models\Fda\FdaWddLicense;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Support\Fda\AddressFingerprint;
use App\Support\MasterData\SiteAtpReadiness;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Partner FDA registry data must never decide whether one of our own docks is
 * licensed: ingest may not stamp a partner WDD facility onto an organization
 * facility, and the cleanup command repairs facilities where it already did.
 */
class OrgFacilityAtpLeakTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $orgIds = [];

    /** @var list<int> */
    private array $tenantSiteIds = [];

    /** @var list<int> */
    private array $tenantPartnerIds = [];

    /** @var list<string> */
    private array $cleanupGlns = [];

    protected function tearDown(): void
    {
        $this->cleanupTenantFixtures();
        parent::tearDown();
    }

    #[Test]
    public function location_ingest_does_not_stamp_a_partner_fda_site_onto_an_organization_facility(): void
    {
        $suffix = (string) random_int(10000, 99999);
        $partnerGln = '03771590'.$suffix;
        $ownDockGln = '03771591'.$suffix;
        $this->cleanupGlns = [$partnerGln, $ownDockGln];

        $this->initializeDemo2Tenant();

        try {
            $ownDock = Site::query()->create([
                'trading_partner_id' => null,
                'name' => 'Own Dock '.$suffix,
                'code' => 'DOCK-'.$suffix,
                'gln' => $ownDockGln,
                'is_organization_facility' => true,
                'is_active' => true,
                'country_code' => 'US',
            ]);
            $this->tenantSiteIds[] = (int) $ownDock->id;

            app(EnsureCatalogPartiesFromEpcisLocations::class)->handle([
                [
                    'gln' => $partnerGln,
                    'name' => 'Leaky Partner '.$suffix,
                    'country_code' => 'US',
                ],
                [
                    'gln' => $ownDockGln,
                    'name' => 'Own Dock '.$suffix,
                    'street_address' => '5 Dock Way',
                    'city' => 'Austin',
                    'state' => 'TX',
                    'country_code' => 'US',
                ],
            ], [
                'source_owning_party_gln' => $partnerGln,
                'source_location_gln' => $ownDockGln,
            ]);

            $ownDock->refresh();

            $this->assertNull($ownDock->fda_wdd_facility_id);
            $this->assertNull($ownDock->fda_establishment_id);
            $this->assertNull($ownDock->trading_partner_id);
            $this->assertTrue((bool) $ownDock->is_organization_facility);
        } finally {
            $this->cleanupTenantFixtures();
        }
    }

    #[Test]
    public function cleanup_command_deactivates_leaked_licenses_and_unlinks_the_partner_fda_site(): void
    {
        [$organization, $facility, $wddLicense] = $this->createPartnerWddFixture();

        $this->initializeDemo2Tenant();

        try {
            $tenantPartner = TradingPartner::query()->create([
                'fda_organization_id' => $organization->id,
                'name' => 'SSOR CUT2 Leak Partner '.Str::random(5),
                'gln' => fake()->unique()->numerify('#############'),
                'partner_type' => PartnerType::Wholesaler,
                'country_code' => 'US',
                'is_active' => true,
            ]);
            $this->tenantPartnerIds[] = (int) $tenantPartner->id;

            $orgSite = Site::query()->create([
                'trading_partner_id' => null,
                'fda_wdd_facility_id' => $facility->id,
                'name' => 'SSOR CUT2 Leaked Org Dock '.Str::random(5),
                'is_organization_facility' => true,
                'is_active' => true,
                'country_code' => 'US',
            ]);
            $this->tenantSiteIds[] = (int) $orgSite->id;

            $leaked = AtpLicense::query()->create([
                'site_id' => $orgSite->id,
                'fda_wdd_license_id' => $wddLicense->id,
                'facility_type' => FacilityType::Wdd,
                'license_number' => 'SSOR-CUT2-LEAKED-001',
                'license_state' => 'IL',
                'license_expiration_date' => now()->addYears(2)->toDateString(),
                'reporting_year' => (int) now()->year,
            ]);

            $ownLicense = AtpLicense::query()->create([
                'site_id' => $orgSite->id,
                'facility_type' => FacilityType::Wdd,
                'license_number' => 'SSOR-CUT2-OWN-001',
                'license_state' => 'IL',
                'license_expiration_date' => now()->addYears(2)->toDateString(),
                'reporting_year' => (int) now()->year,
            ]);

            tenancy()->end();

            $this->artisan('tracepharma:clean-org-facility-atp', [
                '--tenants' => [self::DEMO2_TENANT_ID],
            ])->assertSuccessful();

            tenancy()->initialize(Tenant::query()->findOrFail(self::DEMO2_TENANT_ID));

            $this->assertFalse((bool) $leaked->fresh()?->is_active);
            $this->assertTrue((bool) $ownLicense->fresh()?->is_active);
            $this->assertNull($orgSite->fresh()?->fda_wdd_facility_id);
            $this->assertNull($orgSite->fresh()?->fda_establishment_id);
        } finally {
            $this->cleanupTenantFixtures();
        }
    }

    #[Test]
    public function readiness_ignores_a_leaked_partner_license_once_it_is_deactivated(): void
    {
        [$organization, $facility, $wddLicense] = $this->createPartnerWddFixture();

        $tenant = $this->initializeDemo2Tenant();
        $priorState = $tenant->receiving_state;
        $tenant->receiving_state = 'IL';
        $tenant->save();
        tenancy()->end();
        tenancy()->initialize($tenant->fresh());

        try {
            $tenantPartner = TradingPartner::query()->create([
                'fda_organization_id' => $organization->id,
                'name' => 'SSOR CUT2 Leak Readiness Partner '.Str::random(5),
                'gln' => fake()->unique()->numerify('#############'),
                'partner_type' => PartnerType::Wholesaler,
                'country_code' => 'US',
                'is_active' => true,
            ]);
            $this->tenantPartnerIds[] = (int) $tenantPartner->id;

            $orgSite = Site::query()->create([
                'trading_partner_id' => null,
                'fda_wdd_facility_id' => $facility->id,
                'name' => 'SSOR CUT2 Leaked Readiness Dock '.Str::random(5),
                'is_organization_facility' => true,
                'is_active' => true,
                'country_code' => 'US',
            ]);
            $this->tenantSiteIds[] = (int) $orgSite->id;

            AtpLicense::query()->create([
                'site_id' => $orgSite->id,
                'fda_wdd_license_id' => $wddLicense->id,
                'facility_type' => FacilityType::Wdd,
                'license_number' => 'SSOR-CUT2-LEAKED-READY-001',
                'license_state' => 'IL',
                'license_expiration_date' => now()->addYears(2)->toDateString(),
                'reporting_year' => (int) now()->year,
            ]);

            $this->assertSame(
                SiteAtpReadinessStatus::Ready,
                SiteAtpReadiness::summarize($orgSite->fresh())['status'],
            );

            tenancy()->end();

            $this->artisan('tracepharma:clean-org-facility-atp', [
                '--tenants' => [self::DEMO2_TENANT_ID],
            ])->assertSuccessful();

            tenancy()->initialize(Tenant::query()->findOrFail(self::DEMO2_TENANT_ID));

            $this->assertSame(
                SiteAtpReadinessStatus::NoLicenses,
                SiteAtpReadiness::summarize($orgSite->fresh())['status'],
            );
        } finally {
            if (tenancy()->initialized) {
                $current = tenant();
                $current->receiving_state = $priorState;
                $current->save();
            }

            $this->cleanupTenantFixtures();
        }
    }

    /**
     * @return array{0: FdaOrganization, 1: FdaWddFacility, 2: FdaWddLicense}
     */
    private function createPartnerWddFixture(): array
    {
        $organization = FdaOrganization::query()->create([
            'original_name' => 'SSOR CUT2 Leak Org '.Str::random(6),
            'canonical_name' => 'SSOR CUT2 LEAK ORG '.Str::random(6),
            'name' => 'SSOR CUT2 Leak Org '.Str::random(6),
            'partner_type' => PartnerType::Wholesaler,
            'country_code' => 'US',
            'is_active' => true,
        ]);
        $this->orgIds[] = (int) $organization->id;

        $facility = FdaWddFacility::query()->create([
            'fda_organization_id' => $organization->id,
            'facility_type' => FacilityType::Wdd,
            'name' => 'SSOR CUT2 Leak DC',
            'facility_name' => 'SSOR CUT2 Leak DC',
            'address_fingerprint' => AddressFingerprint::make('9 Leak St', 'Chicago', 'IL', '60601', 'US'),
            'is_active' => true,
        ]);

        $license = FdaWddLicense::query()->create([
            'fda_wdd_facility_id' => $facility->id,
            'license_number' => 'SSOR-CUT2-LEAK-'.Str::random(6),
            'jurisdiction' => 'IL',
            'expiration_date' => now()->addYears(2),
            'reporting_year' => (int) now()->year,
            'is_active' => true,
        ]);

        return [$organization, $facility, $license];
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

    private function cleanupTenantFixtures(): void
    {
        if (tenancy()->initialized) {
            if ($this->tenantSiteIds !== []) {
                AtpLicense::query()->whereIn('site_id', $this->tenantSiteIds)->delete();
                Site::query()->whereIn('id', $this->tenantSiteIds)->delete();
            }

            if ($this->cleanupGlns !== []) {
                Site::query()->whereIn('gln', $this->cleanupGlns)->delete();
                TradingPartner::query()->whereIn('gln', $this->cleanupGlns)->delete();
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

        $this->tenantSiteIds = [];
        $this->tenantPartnerIds = [];
        $this->cleanupGlns = [];
        $this->orgIds = [];
    }
}
