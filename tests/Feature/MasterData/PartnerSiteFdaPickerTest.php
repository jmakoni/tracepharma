<?php

namespace Tests\Feature\MasterData;

use App\Enums\FacilityType;
use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\TradingPartners\Pages\ViewTradingPartner;
use App\Filament\App\Resources\TradingPartners\RelationManagers\SitesRelationManager;
use App\Filament\App\Support\FdaPicker;
use App\Models\AtpLicense;
use App\Models\Fda\FdaEstablishment;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaWddFacility;
use App\Models\Fda\FdaWddLicense;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Fda\AddressFingerprint;
use App\Support\Fda\CompanyNameNormalizer;
use App\Support\Gs1\Gtin;
use App\Support\MasterData\PartnerSiteCreate;
use DomainException;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Partner Sites tab: one FDA location picker scoped to the partner's organization.
 */
class PartnerSiteFdaPickerTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $orgIds = [];

    /** @var list<int> */
    private array $establishmentIds = [];

    /** @var list<int> */
    private array $facilityIds = [];

    /** @var list<int> */
    private array $licenseIds = [];

    /** @var list<int> */
    private array $userIds = [];

    /** @var list<int> */
    private array $partnerIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    protected function tearDown(): void
    {
        $this->cleanupTenantRows();

        if ($this->licenseIds !== []) {
            FdaWddLicense::query()->whereIn('id', $this->licenseIds)->delete();
        }

        if ($this->facilityIds !== []) {
            FdaWddFacility::query()->whereIn('id', $this->facilityIds)->delete();
        }

        if ($this->establishmentIds !== []) {
            FdaEstablishment::query()->whereIn('id', $this->establishmentIds)->delete();
        }

        if ($this->orgIds !== []) {
            FdaOrganization::query()->whereIn('id', $this->orgIds)->delete();
        }

        parent::tearDown();
    }

    #[Test]
    public function blank_search_without_organization_returns_no_locations(): void
    {
        $this->seedFixtures();

        $this->assertSame([], FdaPicker::partnerLocationOptions(null, null));
        $this->assertSame([], FdaPicker::partnerLocationOptions('', null));
        $this->assertSame([], FdaPicker::partnerLocationOptions('Hub', null));
    }

    #[Test]
    public function blank_search_preloads_that_orgs_establishments_and_wdd_only(): void
    {
        [$orgA, $orgB, $estA, $facA, $facB, $estB] = $this->seedFixtures();

        $options = FdaPicker::partnerLocationOptions(null, (int) $orgA->id);

        $this->assertArrayHasKey('est:'.$estA->id, $options);
        $this->assertArrayHasKey('wdd:'.$facA->id, $options);
        $this->assertArrayNotHasKey('est:'.$estB->id, $options);
        $this->assertArrayNotHasKey('wdd:'.$facB->id, $options);
        $this->assertArrayNotHasKey('org:'.$orgA->id, $options);
        $this->assertArrayNotHasKey('org:'.$orgB->id, $options);
        $this->assertStringContainsString('Plant', $options['est:'.$estA->id]);
        $this->assertStringContainsString('Warehouse', $options['wdd:'.$facA->id]);
    }

    #[Test]
    public function typed_search_stays_inside_the_partner_organization(): void
    {
        [$orgA, , $estA, $facA, $facB] = $this->seedFixtures();

        $byPlant = FdaPicker::partnerLocationOptions((string) $estA->name, (int) $orgA->id);
        $this->assertArrayHasKey('est:'.$estA->id, $byPlant);
        $this->assertArrayNotHasKey('wdd:'.$facB->id, $byPlant);

        $byHub = FdaPicker::partnerLocationOptions((string) $facA->city, (int) $orgA->id);
        $this->assertArrayHasKey('wdd:'.$facA->id, $byHub);
        $this->assertArrayNotHasKey('wdd:'.$facB->id, $byHub);
    }

    #[Test]
    public function already_mirrored_stamps_and_glns_are_omitted(): void
    {
        [$orgA, , $estA, $facA] = $this->seedFixtures();
        $this->initializeDemo2Tenant();

        try {
            $partner = $this->createManufacturer($orgA);
            $usedGln = $this->uniqueGln('31');
            $estA->update(['gln' => $usedGln]);

            $stamped = Site::query()->create([
                'trading_partner_id' => $partner->id,
                'name' => 'Already mirrored plant',
                'fda_establishment_id' => $estA->id,
                'gln' => $usedGln,
                'country_code' => 'US',
                'is_active' => true,
            ]);
            $this->siteIds[] = (int) $stamped->id;

            $options = FdaPicker::partnerLocationOptions(null, (int) $orgA->id);

            $this->assertArrayNotHasKey('est:'.$estA->id, $options);
            $this->assertArrayHasKey('wdd:'.$facA->id, $options);
        } finally {
            $this->cleanupTenantRows();
        }
    }

    #[Test]
    public function manufacturer_may_use_a_same_org_wdd_facility(): void
    {
        [$orgA, , , $facA] = $this->seedFixtures();
        $this->initializeDemo2Tenant();

        try {
            $partner = new TradingPartner([
                'fda_organization_id' => $orgA->id,
                'partner_type' => PartnerType::Manufacturer,
            ]);

            $this->assertNull(PartnerSiteCreate::wddFacilityProblemFor($partner, $facA));
        } finally {
            $this->cleanupTenantRows();
        }
    }

    #[Test]
    public function other_org_location_is_rejected(): void
    {
        [$orgA, , , , $facB, $estB] = $this->seedFixtures();

        $partner = new TradingPartner([
            'fda_organization_id' => $orgA->id,
            'partner_type' => PartnerType::Manufacturer,
        ]);

        $this->assertNotNull(PartnerSiteCreate::wddFacilityProblemFor($partner, $facB));
        $this->assertNotNull(PartnerSiteCreate::establishmentProblemFor($partner, $estB));

        $this->expectException(DomainException::class);
        PartnerSiteCreate::resolveCreateData($partner, [
            'create_mode' => PartnerSiteCreate::MODE_FDA,
            'fda_pick' => 'wdd:'.$facB->id,
            'name' => 'Wrong org',
        ]);
    }

    #[Test]
    public function manufacturer_creates_a_site_from_an_establishment_pick(): void
    {
        [$orgA, , $estA] = $this->seedFixtures();
        $this->initializeDemo2Tenant();

        try {
            $this->actAsOwner();
            $partner = $this->createManufacturer($orgA);

            Livewire::test(SitesRelationManager::class, [
                'ownerRecord' => $partner,
                'pageClass' => ViewTradingPartner::class,
            ])
                ->mountAction(TestAction::make('create')->table())
                ->fillForm([
                    'create_mode' => PartnerSiteCreate::MODE_FDA,
                    '_fda_pick_partner_location' => 'est:'.$estA->id,
                ])
                ->callMountedAction()
                ->assertHasNoActionErrors();

            $site = Site::query()
                ->where('trading_partner_id', $partner->id)
                ->where('fda_establishment_id', $estA->id)
                ->first();
            $this->assertNotNull($site);
            $this->siteIds[] = (int) $site->id;
            $this->assertSame($estA->name, $site->name);
            $this->assertSame($estA->gln, $site->gln);
            $this->assertNull($site->fda_wdd_facility_id);
        } finally {
            $this->cleanupTenantRows();
        }
    }

    #[Test]
    public function manufacturer_creates_a_site_from_a_same_org_wdd_pick_and_copies_licenses(): void
    {
        [$orgA, , , $facA] = $this->seedFixtures();
        $license = FdaWddLicense::query()->create([
            'fda_wdd_facility_id' => $facA->id,
            'license_number' => 'SSOR-PS-WDD-'.$this->suffix(),
            'jurisdiction' => 'TX',
            'expiration_date' => now()->addYear(),
            'reporting_year' => (int) now()->year,
            'is_active' => true,
        ]);
        $this->licenseIds[] = (int) $license->id;

        $this->initializeDemo2Tenant();

        try {
            $this->actAsOwner();
            $partner = $this->createManufacturer($orgA);

            Livewire::test(SitesRelationManager::class, [
                'ownerRecord' => $partner,
                'pageClass' => ViewTradingPartner::class,
            ])
                ->mountAction(TestAction::make('create')->table())
                ->fillForm([
                    'create_mode' => PartnerSiteCreate::MODE_FDA,
                    '_fda_pick_partner_location' => 'wdd:'.$facA->id,
                ])
                ->callMountedAction()
                ->assertHasNoActionErrors();

            $site = Site::query()
                ->where('trading_partner_id', $partner->id)
                ->where('fda_wdd_facility_id', $facA->id)
                ->first();
            $this->assertNotNull($site);
            $this->siteIds[] = (int) $site->id;
            $this->assertSame($facA->name, $site->name);
            $this->assertSame($facA->gln, $site->gln);
            $this->assertNull($site->fda_establishment_id);
            $this->assertSame(1, AtpLicense::query()->where('site_id', $site->id)->count());
        } finally {
            $this->cleanupTenantRows();
        }
    }

    /**
     * @return array{0: FdaOrganization, 1: FdaOrganization, 2: FdaEstablishment, 3: FdaWddFacility, 4: FdaWddFacility, 5: FdaEstablishment}
     */
    private function seedFixtures(): array
    {
        $suffix = $this->suffix();

        $orgA = $this->organization('SSOR Ps Org A '.$suffix, PartnerType::Manufacturer);
        $orgB = $this->organization('SSOR Ps Org B '.$suffix, PartnerType::Wholesaler);

        $estA = $this->establishment($orgA, 'SSOR Ps Plant A '.$suffix, 'PlantCityA'.$suffix, '10 Pstreet '.$suffix, $this->uniqueGln('11'));
        $estB = $this->establishment($orgB, 'SSOR Ps Plant B '.$suffix, 'PlantCityB'.$suffix, '20 Pstreet '.$suffix, $this->uniqueGln('12'));
        $facA = $this->facility($orgA, 'SSOR Ps Hub A '.$suffix, 'HubCityA'.$suffix, '11 Wddst '.$suffix, $this->uniqueGln('21'));
        $facB = $this->facility($orgB, 'SSOR Ps Hub B '.$suffix, 'HubCityB'.$suffix, '22 Wddst '.$suffix, $this->uniqueGln('22'));

        return [$orgA, $orgB, $estA, $facA, $facB, $estB];
    }

    private function organization(string $name, PartnerType $type): FdaOrganization
    {
        $org = FdaOrganization::query()->create([
            'original_name' => $name,
            'canonical_name' => CompanyNameNormalizer::canonical($name),
            'name' => $name,
            'partner_type' => $type,
            'street_address' => '100 '.$name,
            'city' => 'OrgCity',
            'state_province' => 'TX',
            'postal_code' => '78701',
            'country_code' => 'US',
            'is_active' => true,
        ]);
        $this->orgIds[] = (int) $org->id;

        return $org;
    }

    private function establishment(
        FdaOrganization $org,
        string $name,
        string $city,
        string $street,
        string $gln,
    ): FdaEstablishment {
        $establishment = FdaEstablishment::query()->create([
            'fda_organization_id' => $org->id,
            'name' => $name,
            'firm_name' => $name,
            'gln' => $gln,
            'street_address' => $street,
            'city' => $city,
            'state_province' => 'TX',
            'postal_code' => '78701',
            'country_code' => 'US',
            'full_address' => $street.', '.$city.', TX',
            'address_fingerprint' => AddressFingerprint::make($street, $city, 'TX', '78701', 'US'),
            'is_active' => true,
        ]);
        $this->establishmentIds[] = (int) $establishment->id;

        return $establishment;
    }

    private function facility(
        FdaOrganization $org,
        string $name,
        string $city,
        string $street,
        string $gln,
    ): FdaWddFacility {
        $facility = FdaWddFacility::query()->create([
            'fda_organization_id' => $org->id,
            'facility_type' => FacilityType::Wdd,
            'name' => $name,
            'facility_name' => $name,
            'gln' => $gln,
            'street_address' => $street,
            'city' => $city,
            'state_province' => 'TX',
            'postal_code' => '78701',
            'country_code' => 'US',
            'full_address' => $street.', '.$city.', TX',
            'address_fingerprint' => AddressFingerprint::make($street, $city, 'TX', '78701', 'US'),
            'is_active' => true,
        ]);
        $this->facilityIds[] = (int) $facility->id;

        return $facility;
    }

    private function createManufacturer(FdaOrganization $org): TradingPartner
    {
        $partner = TradingPartner::query()->create([
            'fda_organization_id' => $org->id,
            'name' => $org->name,
            'gln' => $this->uniqueGln('40'),
            'partner_type' => PartnerType::Manufacturer,
            'country_code' => 'US',
            'is_active' => true,
        ]);
        $this->partnerIds[] = (int) $partner->id;

        return $partner;
    }

    private function actAsOwner(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

        $user = User::factory()->create();
        $this->userIds[] = (int) $user->id;
        $user->syncRoles([TenantRole::Owner->value]);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('app'));

        return $user;
    }

    private function uniqueGln(string $marker): string
    {
        do {
            $body = '094226'.$marker.str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            $gln = $body.Gtin::checkDigit($body);
        } while (
            FdaOrganization::query()->where('gln', $gln)->exists()
            || FdaEstablishment::query()->where('gln', $gln)->exists()
            || FdaWddFacility::query()->where('gln', $gln)->exists()
        );

        return $gln;
    }

    private function suffix(): string
    {
        return Str::lower(Str::random(6));
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
        Filament::setCurrentPanel(Filament::getPanel('app'));

        return $tenant;
    }

    private function cleanupTenantRows(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->siteIds !== []) {
            AtpLicense::query()->whereIn('site_id', $this->siteIds)->delete();
            Site::query()->whereIn('id', $this->siteIds)->delete();
        }

        if ($this->partnerIds !== []) {
            AtpLicense::query()
                ->whereIn('site_id', Site::query()->whereIn('trading_partner_id', $this->partnerIds)->pluck('id'))
                ->delete();
            Site::query()->whereIn('trading_partner_id', $this->partnerIds)->delete();
            TradingPartner::query()->whereIn('id', $this->partnerIds)->delete();
        }

        if ($this->userIds !== []) {
            User::query()->whereIn('id', $this->userIds)->delete();
        }

        $this->siteIds = [];
        $this->partnerIds = [];
        $this->userIds = [];

        tenancy()->end();
    }
}
