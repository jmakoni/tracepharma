<?php

namespace Tests\Feature\MasterData;

use App\Enums\FacilityType;
use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\Sites\Pages\CreateSite;
use App\Filament\App\Support\FdaPicker;
use App\Models\Fda\FdaEstablishment;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaWddFacility;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Fda\AddressFingerprint;
use App\Support\Fda\CompanyNameNormalizer;
use App\Support\Fda\FdaPrefill;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Sites create: WDD facility select preloads under the chosen establishment's org
 * and still typeahead-filters; both FDA stamps can coexist on the tenant site.
 */
class SiteCreateWddFacilityPickerTest extends TestCase
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

    protected function tearDown(): void
    {
        if ($this->facilityIds !== []) {
            FdaWddFacility::query()->whereIn('id', $this->facilityIds)->delete();
        }

        if ($this->establishmentIds !== []) {
            FdaEstablishment::query()->whereIn('id', $this->establishmentIds)->delete();
        }

        if ($this->orgIds !== []) {
            FdaOrganization::query()->whereIn('id', $this->orgIds)->delete();
        }

        if (tenancy()->initialized && $this->userIds !== []) {
            User::query()->whereIn('id', $this->userIds)->delete();
            tenancy()->end();
        }

        parent::tearDown();
    }

    #[Test]
    public function blank_search_without_organization_returns_no_facilities(): void
    {
        $this->seedScopedFacilities();

        $this->assertSame([], FdaPicker::wddFacilityOptions(null));
        $this->assertSame([], FdaPicker::wddFacilityOptions(''));
    }

    #[Test]
    public function blank_organization_search_is_type_first_for_national_list(): void
    {
        $this->seedScopedFacilities();

        $this->assertSame([], FdaPicker::organizationOptions(null));
        $this->assertSame([], FdaPicker::organizationOptions(''));
    }

    #[Test]
    public function typed_organization_search_filters_by_name(): void
    {
        [$orgA, $orgB] = $this->seedScopedFacilities();

        $options = FdaPicker::organizationOptions('Wdd Org A');

        $this->assertArrayHasKey($orgA->id, $options);
        $this->assertArrayNotHasKey($orgB->id, $options);
    }

    #[Test]
    public function organization_option_shows_name_then_address_on_the_next_line(): void
    {
        [$orgA] = $this->seedScopedFacilities();

        $options = FdaPicker::organizationOptions($orgA->name);
        $label = $options[$orgA->id] ?? '';

        $this->assertStringContainsString('<strong>'.e($orgA->name).'</strong>', $label);
        $this->assertStringContainsString((string) $orgA->full_address, $label);
        $this->assertStringContainsString('<br>', $label);
        $this->assertLessThan(
            strpos($label, (string) $orgA->full_address),
            strpos($label, $orgA->name),
        );
    }

    #[Test]
    public function organization_option_without_address_is_name_only(): void
    {
        $suffix = $this->suffix();
        $org = FdaOrganization::query()->create([
            'original_name' => 'SSOR Nameless Addr '.$suffix,
            'canonical_name' => CompanyNameNormalizer::canonical('SSOR Nameless Addr '.$suffix),
            'name' => 'SSOR Nameless Addr '.$suffix,
            'partner_type' => PartnerType::Wholesaler,
            'is_active' => true,
        ]);
        $this->orgIds[] = (int) $org->id;

        $label = FdaPicker::organizationOptions($org->name)[$org->id] ?? '';

        $this->assertSame('<strong>'.e($org->name).'</strong>', $label);
        $this->assertStringNotContainsString('<br>', $label);
    }

    #[Test]
    public function typed_organization_search_filters_by_city_street_and_zip(): void
    {
        [$orgA, $orgB] = $this->seedScopedFacilities();

        $byCity = FdaPicker::organizationOptions((string) $orgA->city);
        $this->assertArrayHasKey($orgA->id, $byCity);
        $this->assertArrayNotHasKey($orgB->id, $byCity);

        $byStreet = FdaPicker::organizationOptions((string) $orgA->street_address);
        $this->assertArrayHasKey($orgA->id, $byStreet);
        $this->assertArrayNotHasKey($orgB->id, $byStreet);

        $byZip = FdaPicker::organizationOptions((string) $orgA->postal_code);
        $this->assertArrayHasKey($orgA->id, $byZip);
        $this->assertArrayNotHasKey($orgB->id, $byZip);
    }

    #[Test]
    public function blank_search_with_organization_preloads_that_orgs_facilities_only(): void
    {
        [$orgA, $orgB, $facA1, $facA2, $facB] = $this->seedScopedFacilities();

        $options = FdaPicker::wddFacilityOptions(null, (int) $orgA->id);

        $this->assertArrayHasKey($facA1->id, $options);
        $this->assertArrayHasKey($facA2->id, $options);
        $this->assertArrayNotHasKey($facB->id, $options);
        $this->assertCount(2, $options);
    }

    #[Test]
    public function typed_search_filters_within_organization_scope(): void
    {
        [$orgA, $orgB, $facA1, $facA2, $facB] = $this->seedScopedFacilities();

        $options = FdaPicker::wddFacilityOptions('Alpha Hub', (int) $orgA->id);

        $this->assertArrayHasKey($facA1->id, $options);
        $this->assertArrayNotHasKey($facA2->id, $options);
        $this->assertArrayNotHasKey($facB->id, $options);
    }

    #[Test]
    public function typed_search_without_organization_searches_all_active_facilities(): void
    {
        [$orgA, $orgB, $facA1, $facA2, $facB] = $this->seedScopedFacilities();

        $options = FdaPicker::wddFacilityOptions('Beta Other');

        $this->assertArrayHasKey($facB->id, $options);
        $this->assertArrayNotHasKey($facA1->id, $options);
        $this->assertArrayNotHasKey($facA2->id, $options);
    }

    #[Test]
    public function wdd_prefill_keeps_an_existing_establishment_stamp(): void
    {
        [$orgA] = $this->seedScopedFacilities();
        $facility = FdaWddFacility::query()
            ->where('fda_organization_id', $orgA->id)
            ->orderBy('id')
            ->firstOrFail();

        $attrs = FdaPrefill::wddFacilityAttributes($facility);

        $this->assertSame($facility->id, $attrs['fda_wdd_facility_id']);
        $this->assertArrayNotHasKey('fda_establishment_id', $attrs);
    }

    #[Test]
    public function create_site_form_prefills_from_establishment_then_wdd_without_clearing_establishment(): void
    {
        [$orgA, , $facA1] = $this->seedScopedFacilities();

        $establishment = FdaEstablishment::query()->create([
            'fda_organization_id' => $orgA->id,
            'name' => 'SSOR Est '.$this->suffix(),
            'firm_name' => 'SSOR Est Firm',
            'fei_number' => '9'.substr((string) time(), -8),
            'city' => 'Austin',
            'state_province' => 'TX',
            'address_fingerprint' => AddressFingerprint::make('1 Est St', 'Austin', 'TX', '78701', 'US'),
            'is_active' => true,
        ]);
        $this->establishmentIds[] = (int) $establishment->id;

        $this->initializeDemo2Tenant();

        try {
            $user = User::factory()->create();
            $this->userIds[] = (int) $user->id;
            $user->syncRoles([TenantRole::Owner->value]);

            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            Livewire::test(CreateSite::class)
                ->fillForm([
                    '_fda_pick_fda_establishment_id' => (string) $establishment->id,
                ])
                ->assertFormSet([
                    'fda_establishment_id' => $establishment->id,
                    'fda_wdd_facility_id' => null,
                    'name' => $establishment->name,
                ])
                ->fillForm([
                    '_fda_pick_fda_wdd_facility_id' => (string) $facA1->id,
                ])
                ->assertFormSet([
                    'fda_establishment_id' => $establishment->id,
                    'fda_wdd_facility_id' => $facA1->id,
                    'name' => $facA1->name ?: $facA1->facility_name,
                ]);
        } finally {
            if (tenancy()->initialized) {
                tenancy()->end();
            }
        }
    }

    /**
     * @return array{0: FdaOrganization, 1: FdaOrganization, 2: FdaWddFacility, 3: FdaWddFacility, 4: FdaWddFacility}
     */
    private function seedScopedFacilities(): array
    {
        $suffix = $this->suffix();

        $orgA = FdaOrganization::query()->create([
            'original_name' => 'SSOR Wdd Org A '.$suffix,
            'canonical_name' => CompanyNameNormalizer::canonical('SSOR Wdd Org A '.$suffix),
            'name' => 'SSOR Wdd Org A '.$suffix,
            'partner_type' => PartnerType::Wholesaler,
            'street_address' => '100 Ssora '.$suffix,
            'city' => 'CityA'.$suffix,
            'state_province' => 'TX',
            'postal_code' => '9'.$suffix,
            'country_code' => 'US',
            'full_address' => '100 Ssora '.$suffix.', CityA'.$suffix.', TX',
            'is_active' => true,
        ]);
        $orgB = FdaOrganization::query()->create([
            'original_name' => 'SSOR Wdd Org B '.$suffix,
            'canonical_name' => CompanyNameNormalizer::canonical('SSOR Wdd Org B '.$suffix),
            'name' => 'SSOR Wdd Org B '.$suffix,
            'partner_type' => PartnerType::Wholesaler,
            'street_address' => '200 Ssorb '.$suffix,
            'city' => 'CityB'.$suffix,
            'state_province' => 'TX',
            'postal_code' => '8'.$suffix,
            'country_code' => 'US',
            'full_address' => '200 Ssorb '.$suffix.', CityB'.$suffix.', TX',
            'is_active' => true,
        ]);
        $this->orgIds[] = (int) $orgA->id;
        $this->orgIds[] = (int) $orgB->id;

        $facA1 = $this->facility($orgA, 'SSOR Alpha Hub '.$suffix, 'Austin');
        $facA2 = $this->facility($orgA, 'SSOR Alpha Depot '.$suffix, 'Dallas');
        $facB = $this->facility($orgB, 'SSOR Beta Other '.$suffix, 'Houston');

        return [$orgA, $orgB, $facA1, $facA2, $facB];
    }

    private function facility(FdaOrganization $org, string $name, string $city): FdaWddFacility
    {
        $facility = FdaWddFacility::query()->create([
            'fda_organization_id' => $org->id,
            'facility_type' => FacilityType::Wdd,
            'name' => $name,
            'facility_name' => $name,
            'city' => $city,
            'state_province' => 'TX',
            'country_code' => 'US',
            'address_fingerprint' => AddressFingerprint::make('1 Ss or St', $city, 'TX', '78701', 'US'),
            'is_active' => true,
        ]);
        $this->facilityIds[] = (int) $facility->id;

        return $facility;
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
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        Filament::setCurrentPanel(Filament::getPanel('app'));

        return $tenant;
    }
}
