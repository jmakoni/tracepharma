<?php

namespace Tests\Feature\MasterData;

use App\Enums\AtpVerificationSource;
use App\Enums\FacilityType;
use App\Enums\PartnerType;
use App\Enums\SiteAtpReadinessStatus;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\TradingPartners\Pages\ViewTradingPartner;
use App\Models\AtpLicense;
use App\Models\Fda\FdaOrganization;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Fda\CompanyNameNormalizer;
use App\Support\MasterData\PartnerAtpSiteCoverage;
use App\Support\MasterData\SiteAtpReadiness;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PartnerAtpSiteCoverageTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $orgIds = [];

    /** @var list<int> */
    private array $partnerIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $userIds = [];

    protected function tearDown(): void
    {
        SiteAtpReadiness::forget();
        $this->cleanupTenantRows();

        if ($this->orgIds !== []) {
            FdaOrganization::query()->whereIn('id', $this->orgIds)->delete();
        }

        parent::tearDown();
    }

    #[Test]
    public function manufacturer_plant_and_wdd_site_are_listed_with_separate_sources(): void
    {
        $org = $this->organization();
        $tenant = $this->initializeDemo2Tenant();
        $this->setTenantReceivingState($tenant, 'CA');

        try {
            $partner = $this->createPartner($org, [
                'street_address' => '1200 E Business Center Dr',
                'city' => 'Mt Prospect',
                'state' => 'IL',
                'zipcode' => '60056',
            ]);
            $hq = $this->createSite($partner, [
                'name' => 'HQ Plant',
                'is_headquarters' => true,
                'street_address' => '1200 E Business Center Dr',
                'city' => 'Mt Prospect',
                'state' => 'IL',
                'zipcode' => '60056',
            ]);
            $dc = $this->createSite($partner, [
                'name' => 'Glenview DC',
                'fda_wdd_facility_id' => 1970,
                'street_address' => '3400 W Lake Ave',
                'city' => 'Glenview',
                'state' => 'IL',
                'zipcode' => '60026',
            ]);

            $rows = PartnerAtpSiteCoverage::rows($partner->fresh(['sites.atpLicenses']) ?? $partner);

            $this->assertCount(2, $rows);
            $this->assertSame($hq->id, $rows[0]['site_id']);
            $this->assertSame(AtpVerificationSource::FdaDecrs->value, $rows[0]['source']);
            $this->assertSame('FDA DECRS', $rows[0]['source_label']);
            $this->assertSame(SiteAtpReadinessStatus::FdaRegistered->value, $rows[0]['status']);
            $this->assertSame('Ready', $rows[0]['badge_label']);
            $this->assertSame('Manufacturer plant · all states', $rows[0]['note']);

            $this->assertSame($dc->id, $rows[1]['site_id']);
            $this->assertSame(AtpVerificationSource::FdaWdd3pl->value, $rows[1]['source']);
            $this->assertSame('FDA WDD / 3PL', $rows[1]['source_label']);
            $this->assertSame(SiteAtpReadinessStatus::NoLicenses->value, $rows[1]['status']);
            $this->assertSame('0 · CA', $rows[1]['badge_label']);
            $this->assertSame('Needs CA WDD/3PL license', $rows[1]['note']);
        } finally {
            $this->cleanupTenantRows();
        }
    }

    #[Test]
    public function wdd_site_with_receiving_state_license_is_ready(): void
    {
        $org = $this->organization();
        $tenant = $this->initializeDemo2Tenant();
        $this->setTenantReceivingState($tenant, 'CA');

        try {
            $partner = $this->createPartner($org);
            $site = $this->createSite($partner, [
                'name' => 'CA warehouse',
                'fda_wdd_facility_id' => 1980,
                'city' => 'Ontario',
                'state' => 'CA',
            ]);
            AtpLicense::factory()->create([
                'site_id' => $site->id,
                'facility_type' => FacilityType::Wdd,
                'license_state' => 'CA',
            ]);

            $row = PartnerAtpSiteCoverage::rows($partner->fresh(['sites.atpLicenses']) ?? $partner)->first();

            $this->assertSame(AtpVerificationSource::FdaWdd3pl->value, $row['source']);
            $this->assertSame(SiteAtpReadinessStatus::Ready->value, $row['status']);
            $this->assertSame('1 · CA', $row['badge_label']);
            $this->assertNull($row['note']);
        } finally {
            $this->cleanupTenantRows();
        }
    }

    #[Test]
    public function unlinked_manufacturer_site_must_be_on_the_wdd_list(): void
    {
        $org = $this->organization();
        $tenant = $this->initializeDemo2Tenant();
        $this->setTenantReceivingState($tenant, 'CA');

        try {
            $partner = $this->createPartner($org, [
                'street_address' => '1200 E Business Center Dr',
                'city' => 'Mt Prospect',
                'state' => 'IL',
                'zipcode' => '60056',
            ]);
            $this->createSite($partner, [
                'name' => 'Extra yard',
                'street_address' => '999 Other Yard',
                'city' => 'Dallas',
                'state' => 'TX',
                'zipcode' => '75201',
            ]);

            $row = PartnerAtpSiteCoverage::rows($partner->fresh(['sites']) ?? $partner)->first();

            $this->assertNull($row['source']);
            $this->assertSame('Needs WDD / 3PL', $row['source_label']);
            $this->assertSame('Must be on the WDD/3PL list for organization jurisdictions', $row['note']);
        } finally {
            $this->cleanupTenantRows();
        }
    }

    #[Test]
    public function partner_view_shows_site_coverage_instead_of_a_company_stamp(): void
    {
        $org = $this->organization();
        $tenant = $this->initializeDemo2Tenant();
        $this->setTenantReceivingState($tenant, 'CA');

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->createUserWithRole(TenantRole::Owner);
            $partner = $this->createPartner($org, [
                'street_address' => '1200 E Business Center Dr',
                'city' => 'Mt Prospect',
                'state' => 'IL',
                'zipcode' => '60056',
            ]);
            $this->createSite($partner, [
                'name' => 'HQ Plant',
                'is_headquarters' => true,
                'street_address' => '1200 E Business Center Dr',
                'city' => 'Mt Prospect',
                'state' => 'IL',
                'zipcode' => '60056',
            ]);
            $this->createSite($partner, [
                'name' => 'Glenview DC',
                'fda_wdd_facility_id' => 1970,
                'city' => 'Glenview',
                'state' => 'IL',
            ]);

            Livewire::test(ViewTradingPartner::class, ['record' => $partner->getKey()])
                ->assertSee('FDA DECRS')
                ->assertSee('FDA WDD / 3PL')
                ->assertSee('HQ Plant')
                ->assertSee('Glenview DC')
                ->assertSee('Needs CA WDD/3PL license')
                ->assertDontSee('Never verified');
        } finally {
            $this->cleanupTenantRows();
        }
    }

    private function organization(): FdaOrganization
    {
        $name = 'Coverage Org '.$this->suffix();
        $org = FdaOrganization::query()->create([
            'original_name' => $name,
            'canonical_name' => CompanyNameNormalizer::canonical($name),
            'name' => $name,
            'partner_type' => PartnerType::Manufacturer,
            'is_active' => true,
        ]);
        $this->orgIds[] = (int) $org->id;

        return $org;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createPartner(FdaOrganization $org, array $attributes = []): TradingPartner
    {
        $partner = TradingPartner::query()->create(array_merge([
            'fda_organization_id' => $org->id,
            'name' => $org->name,
            'gln' => fake()->unique()->numerify('#############'),
            'partner_type' => PartnerType::Manufacturer,
            'country_code' => 'US',
            'is_active' => true,
        ], $attributes));
        $this->partnerIds[] = (int) $partner->id;

        return $partner;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createSite(TradingPartner $partner, array $attributes): Site
    {
        $site = Site::query()->create(array_merge([
            'trading_partner_id' => $partner->id,
            'name' => $partner->name.' site',
            'country_code' => 'US',
            'is_active' => true,
        ], $attributes));
        $this->siteIds[] = (int) $site->id;

        return $site->fresh(['tradingPartner']) ?? $site;
    }

    private function createUserWithRole(TenantRole $role): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

        $user = User::factory()->create();
        $user->assignRole($role->value);
        $this->userIds[] = (int) $user->getKey();
        $this->actingAs($user);

        return $user;
    }

    private function setTenantReceivingState(Tenant $tenant, ?string $state): void
    {
        $tenant->receiving_state = $state;
        $tenant->save();

        tenancy()->end();
        tenancy()->initialize($tenant->fresh());
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

    private function suffix(): string
    {
        return Str::lower(Str::random(6));
    }
}
