<?php

namespace Tests\Feature\MasterData;

use App\Enums\FacilityType;
use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\TradingPartners\Pages\ListTradingPartners;
use App\Filament\App\Resources\TradingPartners\Pages\ViewTradingPartner;
use App\Models\Fda\FdaEstablishment;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaWddFacility;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Fda\AddressFingerprint;
use App\Support\Fda\CompanyNameNormalizer;
use App\Support\Gs1\Gtin;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TradingPartnerFdaPickSiteCreateTest extends TestCase
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
    private array $userIds = [];

    /** @var list<int> */
    private array $partnerIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    protected function tearDown(): void
    {
        $this->cleanupTenantRows();

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
    public function org_pick_creates_the_partner_and_one_hq_site(): void
    {
        [$orgA] = $this->seedFixtures();
        $this->initializeDemo2Tenant();

        try {
            $this->actAsOwner();

            Livewire::test(ListTradingPartners::class)
                ->mountAction('create')
                ->fillForm([
                    '_fda_pick_fda_organization_id' => 'org:'.$orgA->id,
                ])
                ->callMountedAction()
                ->assertHasNoActionErrors();

            $partner = $this->rememberPartner($orgA->name);
            $sites = Site::query()->where('trading_partner_id', $partner->id)->get();
            $this->siteIds = array_merge($this->siteIds, $sites->pluck('id')->map(fn ($id): int => (int) $id)->all());

            $this->assertSame($orgA->id, $partner->fda_organization_id);
            $this->assertCount(1, $sites);
            $this->assertTrue((bool) $sites->first()?->is_headquarters);
            $this->assertSame($orgA->gln, $sites->first()?->gln);
        } finally {
            $this->cleanupTenantRows();
        }
    }

    #[Test]
    public function establishment_pick_creates_hq_and_the_plant_site(): void
    {
        [$orgA, , $estA] = $this->seedFixtures();
        $this->initializeDemo2Tenant();

        try {
            $this->actAsOwner();

            Livewire::test(ListTradingPartners::class)
                ->mountAction('create')
                ->fillForm([
                    '_fda_pick_fda_organization_id' => 'est:'.$estA->id,
                ])
                ->callMountedAction()
                ->assertHasNoActionErrors();

            $partner = $this->rememberPartner($orgA->name);
            $sites = Site::query()->where('trading_partner_id', $partner->id)->orderBy('id')->get();
            $this->siteIds = array_merge($this->siteIds, $sites->pluck('id')->map(fn ($id): int => (int) $id)->all());

            $this->assertCount(2, $sites);
            $hq = $sites->firstWhere('is_headquarters', true);
            $plant = $sites->firstWhere('fda_establishment_id', $estA->id);

            $this->assertNotNull($hq);
            $this->assertSame($orgA->gln, $hq->gln);
            $this->assertNotNull($plant);
            $this->assertFalse((bool) $plant->is_headquarters);
            $this->assertSame($estA->name, $plant->name);
            $this->assertSame($estA->street_address, $plant->street_address);
            $this->assertSame($estA->gln, $plant->gln);
            $this->assertNotSame($orgA->name, $plant->name);
        } finally {
            $this->cleanupTenantRows();
        }
    }

    #[Test]
    public function wdd_pick_creates_hq_and_the_warehouse_site_not_the_other_orgs(): void
    {
        [$orgA, $orgB, , $facA, $facB] = $this->seedFixtures();
        $this->initializeDemo2Tenant();

        try {
            $this->actAsOwner();

            Livewire::test(ListTradingPartners::class)
                ->mountAction('create')
                ->fillForm([
                    '_fda_pick_fda_organization_id' => 'wdd:'.$facA->id,
                ])
                ->callMountedAction()
                ->assertHasNoActionErrors();

            $partner = $this->rememberPartner($orgA->name);
            $sites = Site::query()->where('trading_partner_id', $partner->id)->orderBy('id')->get();
            $this->siteIds = array_merge($this->siteIds, $sites->pluck('id')->map(fn ($id): int => (int) $id)->all());

            $this->assertCount(2, $sites);
            $this->assertNotNull($sites->firstWhere('fda_wdd_facility_id', $facA->id));
            $this->assertNull($sites->firstWhere('fda_wdd_facility_id', $facB->id));
            $this->assertNull(TradingPartner::query()->where('fda_organization_id', $orgB->id)->first());
        } finally {
            $this->cleanupTenantRows();
        }
    }

    #[Test]
    public function same_gln_as_the_company_stamps_hq_instead_of_a_second_site(): void
    {
        [$orgA, , , $facA] = $this->seedFixtures();
        $facA->update(['gln' => $orgA->gln]);
        $this->initializeDemo2Tenant();

        try {
            $this->actAsOwner();

            Livewire::test(ListTradingPartners::class)
                ->mountAction('create')
                ->fillForm([
                    '_fda_pick_fda_organization_id' => 'wdd:'.$facA->id,
                ])
                ->callMountedAction()
                ->assertHasNoActionErrors();

            $partner = $this->rememberPartner($orgA->name);
            $sites = Site::query()->where('trading_partner_id', $partner->id)->get();
            $this->siteIds = array_merge($this->siteIds, $sites->pluck('id')->map(fn ($id): int => (int) $id)->all());

            $this->assertCount(1, $sites);
            $this->assertTrue((bool) $sites->first()?->is_headquarters);
            $this->assertSame($facA->id, $sites->first()?->fda_wdd_facility_id);
        } finally {
            $this->cleanupTenantRows();
        }
    }

    #[Test]
    public function extra_site_gln_clash_keeps_the_partner_and_hq(): void
    {
        [$orgA, , , $facA] = $this->seedFixtures();
        $this->initializeDemo2Tenant();

        try {
            $this->actAsOwner();

            $blocking = Site::query()->create([
                'name' => 'SSOR Blocking Dock '.$this->suffix(),
                'gln' => $facA->gln,
                'is_organization_facility' => true,
                'is_active' => true,
            ]);
            $this->siteIds[] = (int) $blocking->id;

            Livewire::test(ListTradingPartners::class)
                ->mountAction('create')
                ->fillForm([
                    '_fda_pick_fda_organization_id' => 'wdd:'.$facA->id,
                ])
                ->callMountedAction()
                ->assertHasNoActionErrors();

            $partner = $this->rememberPartner($orgA->name);
            $sites = Site::query()->where('trading_partner_id', $partner->id)->get();
            $this->siteIds = array_merge($this->siteIds, $sites->pluck('id')->map(fn ($id): int => (int) $id)->all());

            $this->assertCount(1, $sites);
            $this->assertTrue((bool) $sites->first()?->is_headquarters);
            $this->assertNull($sites->first()?->fda_wdd_facility_id);
            $this->assertSame($facA->gln, $blocking->fresh()?->gln);
        } finally {
            $this->cleanupTenantRows();
        }
    }

    #[Test]
    public function null_gln_warehouse_and_plant_still_create_a_second_site(): void
    {
        [$orgA, , $estA, $facA] = $this->seedFixtures();
        $estA->update(['gln' => null]);
        $facA->update(['gln' => null]);
        $this->initializeDemo2Tenant();

        try {
            $this->actAsOwner();

            Livewire::test(ListTradingPartners::class)
                ->mountAction('create')
                ->fillForm([
                    '_fda_pick_fda_organization_id' => 'wdd:'.$facA->id,
                ])
                ->callMountedAction()
                ->assertHasNoActionErrors();

            $partner = $this->rememberPartner($orgA->name);
            $sites = Site::query()->where('trading_partner_id', $partner->id)->get();
            $this->siteIds = array_merge($this->siteIds, $sites->pluck('id')->map(fn ($id): int => (int) $id)->all());

            $hq = $sites->firstWhere('is_headquarters', true);
            $warehouse = $sites->firstWhere('fda_wdd_facility_id', $facA->id);

            $this->assertCount(2, $sites);
            $this->assertNotNull($hq);
            $this->assertSame($orgA->street_address, $hq->street_address);
            $this->assertNotSame($facA->id, $hq->fda_wdd_facility_id);
            $this->assertNotNull($warehouse);
            $this->assertFalse((bool) $warehouse->is_headquarters);
            $this->assertSame($facA->name, $warehouse->name);
            $this->assertSame($facA->street_address, $warehouse->street_address);
            $this->assertNull($warehouse->gln);

            $partner->sites()->delete();
            $partner->delete();
            $this->partnerIds = array_values(array_filter($this->partnerIds, fn (int $id): bool => $id !== (int) $partner->id));

            Livewire::test(ListTradingPartners::class)
                ->mountAction('create')
                ->fillForm([
                    '_fda_pick_fda_organization_id' => 'est:'.$estA->id,
                ])
                ->callMountedAction()
                ->assertHasNoActionErrors();

            $plantPartner = $this->rememberPartner($orgA->name);
            $plantSites = Site::query()->where('trading_partner_id', $plantPartner->id)->get();
            $this->siteIds = array_merge($this->siteIds, $plantSites->pluck('id')->map(fn ($id): int => (int) $id)->all());

            $this->assertCount(2, $plantSites);
            $this->assertNotNull($plantSites->firstWhere('fda_establishment_id', $estA->id));
            $this->assertSame($orgA->street_address, $plantSites->firstWhere('is_headquarters', true)?->street_address);
        } finally {
            $this->cleanupTenantRows();
        }
    }

    #[Test]
    public function headquarters_flagged_wdd_with_a_different_gln_still_creates_two_sites(): void
    {
        [$orgA, , , $facA] = $this->seedFixtures();
        $facA->update(['is_headquarters' => true]);
        $this->initializeDemo2Tenant();

        try {
            $this->actAsOwner();

            Livewire::test(ListTradingPartners::class)
                ->mountAction('create')
                ->fillForm([
                    '_fda_pick_fda_organization_id' => 'wdd:'.$facA->id,
                ])
                ->callMountedAction()
                ->assertHasNoActionErrors();

            $partner = $this->rememberPartner($orgA->name);
            $sites = Site::query()->where('trading_partner_id', $partner->id)->get();
            $this->siteIds = array_merge($this->siteIds, $sites->pluck('id')->map(fn ($id): int => (int) $id)->all());

            $hq = $sites->firstWhere('is_headquarters', true);
            $warehouse = $sites->firstWhere('fda_wdd_facility_id', $facA->id);

            $this->assertCount(2, $sites);
            $this->assertNotNull($hq);
            $this->assertNotSame($facA->id, $hq->fda_wdd_facility_id);
            $this->assertNotNull($warehouse);
            $this->assertFalse((bool) $warehouse->is_headquarters);
            $this->assertSame($facA->gln, $warehouse->gln);
        } finally {
            $this->cleanupTenantRows();
        }
    }

    #[Test]
    public function editing_the_partner_does_not_stamp_hq_with_the_picked_warehouse(): void
    {
        [$orgA, , , $facA] = $this->seedFixtures();
        $facA->update(['is_headquarters' => true]);
        $this->initializeDemo2Tenant();

        try {
            $this->actAsOwner();

            Livewire::test(ListTradingPartners::class)
                ->mountAction('create')
                ->fillForm([
                    '_fda_pick_fda_organization_id' => 'wdd:'.$facA->id,
                ])
                ->callMountedAction()
                ->assertHasNoActionErrors();

            $partner = $this->rememberPartner($orgA->name);
            $sites = Site::query()->where('trading_partner_id', $partner->id)->get();
            $this->siteIds = array_merge($this->siteIds, $sites->pluck('id')->map(fn ($id): int => (int) $id)->all());

            $this->assertCount(2, $sites);
            $this->assertNotSame($facA->id, $sites->firstWhere('is_headquarters', true)?->fda_wdd_facility_id);

            Livewire::test(ViewTradingPartner::class, ['record' => $partner->getKey()])
                ->mountAction('edit')
                ->callMountedAction()
                ->assertHasNoActionErrors();

            $sitesAfterEdit = Site::query()->where('trading_partner_id', $partner->id)->get();
            $this->assertCount(2, $sitesAfterEdit);
            $this->assertNotSame($facA->id, $sitesAfterEdit->firstWhere('is_headquarters', true)?->fda_wdd_facility_id);
            $this->assertNotNull($sitesAfterEdit->firstWhere('fda_wdd_facility_id', $facA->id));
        } finally {
            $this->cleanupTenantRows();
        }
    }

    #[Test]
    public function nameless_wdd_pick_creates_a_warehouse_named_site(): void
    {
        [$orgA] = $this->seedFixtures();
        $suffix = $this->suffix();
        $fac = FdaWddFacility::query()->create([
            'fda_organization_id' => $orgA->id,
            'facility_type' => FacilityType::Wdd,
            'name' => null,
            'facility_name' => null,
            'gln' => $this->uniqueGln('50'),
            'street_address' => '88 Nameless '.$suffix,
            'city' => 'NoNameCity'.$suffix,
            'state_province' => 'TX',
            'postal_code' => '78701',
            'country_code' => null,
            'full_address' => '88 Nameless '.$suffix,
            'address_fingerprint' => AddressFingerprint::make('88 Nameless '.$suffix, 'NoNameCity'.$suffix, 'TX', '78701', 'US'),
            'is_active' => true,
        ]);
        $this->facilityIds[] = (int) $fac->id;
        $this->initializeDemo2Tenant();

        try {
            $this->actAsOwner();

            Livewire::test(ListTradingPartners::class)
                ->mountAction('create')
                ->fillForm([
                    '_fda_pick_fda_organization_id' => 'wdd:'.$fac->id,
                ])
                ->callMountedAction()
                ->assertHasNoActionErrors();

            $partner = $this->rememberPartner($orgA->name);
            $sites = Site::query()->where('trading_partner_id', $partner->id)->get();
            $this->siteIds = array_merge($this->siteIds, $sites->pluck('id')->map(fn ($id): int => (int) $id)->all());

            $warehouse = $sites->firstWhere('fda_wdd_facility_id', $fac->id);
            $this->assertNotNull($warehouse);
            $this->assertSame('Warehouse', $warehouse->name);
            $this->assertSame('US', $warehouse->country_code);
        } finally {
            $this->cleanupTenantRows();
        }
    }

    #[Test]
    public function extra_site_code_clash_keeps_the_partner_and_hq(): void
    {
        [$orgA, , , $facA] = $this->seedFixtures();
        $code = 'SSOR-CODE-'.$this->suffix();
        $facA->update(['code' => $code]);
        $this->initializeDemo2Tenant();

        try {
            $this->actAsOwner();

            $blocking = Site::query()->create([
                'name' => 'SSOR Blocking Code '.$this->suffix(),
                'code' => $code,
                'is_organization_facility' => true,
                'is_active' => true,
            ]);
            $this->siteIds[] = (int) $blocking->id;

            Livewire::test(ListTradingPartners::class)
                ->mountAction('create')
                ->fillForm([
                    '_fda_pick_fda_organization_id' => 'wdd:'.$facA->id,
                ])
                ->callMountedAction()
                ->assertHasNoActionErrors();

            $partner = $this->rememberPartner($orgA->name);
            $sites = Site::query()->where('trading_partner_id', $partner->id)->get();
            $this->siteIds = array_merge($this->siteIds, $sites->pluck('id')->map(fn ($id): int => (int) $id)->all());

            $this->assertCount(1, $sites);
            $this->assertTrue((bool) $sites->first()?->is_headquarters);
            $this->assertNull($sites->first()?->fda_wdd_facility_id);
        } finally {
            $this->cleanupTenantRows();
        }
    }

    /**
     * @return array{0: FdaOrganization, 1: FdaOrganization, 2: FdaEstablishment, 3: FdaWddFacility, 4: FdaWddFacility}
     */
    private function seedFixtures(): array
    {
        $suffix = $this->suffix();

        $orgA = FdaOrganization::query()->create([
            'original_name' => 'SSOR Pick Org A '.$suffix,
            'canonical_name' => CompanyNameNormalizer::canonical('SSOR Pick Org A '.$suffix),
            'name' => 'SSOR Pick Org A '.$suffix,
            'partner_type' => PartnerType::Wholesaler,
            'gln' => $this->uniqueGln('10'),
            'street_address' => '100 Picka '.$suffix,
            'city' => 'OrgCityA'.$suffix,
            'state_province' => 'TX',
            'postal_code' => '7'.$suffix,
            'country_code' => 'US',
            'full_address' => '100 Picka '.$suffix.', OrgCityA'.$suffix.', TX',
            'is_active' => true,
        ]);
        $orgB = FdaOrganization::query()->create([
            'original_name' => 'SSOR Pick Org B '.$suffix,
            'canonical_name' => CompanyNameNormalizer::canonical('SSOR Pick Org B '.$suffix),
            'name' => 'SSOR Pick Org B '.$suffix,
            'partner_type' => PartnerType::Wholesaler,
            'gln' => $this->uniqueGln('11'),
            'street_address' => '200 Pickb '.$suffix,
            'city' => 'OrgCityB'.$suffix,
            'state_province' => 'TX',
            'postal_code' => '6'.$suffix,
            'country_code' => 'US',
            'full_address' => '200 Pickb '.$suffix.', OrgCityB'.$suffix.', TX',
            'is_active' => true,
        ]);
        $this->orgIds[] = (int) $orgA->id;
        $this->orgIds[] = (int) $orgB->id;

        $estA = FdaEstablishment::query()->create([
            'fda_organization_id' => $orgA->id,
            'name' => 'SSOR Pick Est A '.$suffix,
            'firm_name' => 'SSOR Pick Est Firm A '.$suffix,
            'gln' => $this->uniqueGln('20'),
            'street_address' => '55 Estreet '.$suffix,
            'city' => 'EstCityA'.$suffix,
            'state_province' => 'TX',
            'postal_code' => '5'.$suffix,
            'country_code' => 'US',
            'full_address' => '55 Estreet '.$suffix.', EstCityA'.$suffix.', TX',
            'address_fingerprint' => AddressFingerprint::make('55 Estreet '.$suffix, 'EstCityA'.$suffix, 'TX', '5'.$suffix, 'US'),
            'is_active' => true,
        ]);
        $this->establishmentIds[] = (int) $estA->id;

        $facA = $this->facility($orgA, 'SSOR Pick Hub A '.$suffix, 'HubCityA'.$suffix, '11 Wddst '.$suffix, $this->uniqueGln('30'));
        $facB = $this->facility($orgB, 'SSOR Pick Hub B '.$suffix, 'HubCityB'.$suffix, '22 Wddst '.$suffix, $this->uniqueGln('31'));

        return [$orgA, $orgB, $estA, $facA, $facB];
    }

    private function facility(FdaOrganization $org, string $name, string $city, string $street, string $gln): FdaWddFacility
    {
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

    private function rememberPartner(string $name): TradingPartner
    {
        $partner = TradingPartner::query()->where('name', $name)->firstOrFail();
        $this->partnerIds[] = (int) $partner->id;

        return $partner;
    }

    private function actAsOwner(): User
    {
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
            $body = '094224'.$marker.str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
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

    private function cleanupTenantRows(): void
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            return;
        }

        if (! tenancy()->initialized) {
            tenancy()->initialize($tenant);
        }

        if ($this->siteIds !== []) {
            Site::query()->whereIn('id', $this->siteIds)->delete();
            $this->siteIds = [];
        }

        if ($this->partnerIds !== []) {
            Site::query()->whereIn('trading_partner_id', $this->partnerIds)->delete();
            TradingPartner::query()->whereIn('id', $this->partnerIds)->delete();
            $this->partnerIds = [];
        }

        if ($this->userIds !== []) {
            User::query()->whereIn('id', $this->userIds)->delete();
            $this->userIds = [];
        }

        tenancy()->end();
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
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        Filament::setCurrentPanel(Filament::getPanel('app'));

        return $tenant;
    }
}
