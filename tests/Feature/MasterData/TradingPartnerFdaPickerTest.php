<?php

namespace Tests\Feature\MasterData;

use App\Enums\FacilityType;
use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\TradingPartners\Pages\ListTradingPartners;
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
use App\Support\Gs1\Gtin;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Trading partner FDA picker: search orgs + establishments + WDD, prefill parent org.
 */
class TradingPartnerFdaPickerTest extends TestCase
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
    public function blank_search_is_type_first_for_national_trading_partner_picker(): void
    {
        $this->seedPartnerPickerFixtures();

        $this->assertSame([], FdaPicker::tradingPartnerOrganizationOptions(null));
        $this->assertSame([], FdaPicker::tradingPartnerOrganizationOptions(''));
    }

    #[Test]
    public function organization_name_search_still_finds_the_parent_org(): void
    {
        [$orgA, $orgB] = $this->seedPartnerPickerFixtures();

        $options = FdaPicker::tradingPartnerOrganizationOptions((string) $orgA->name);

        $this->assertArrayHasKey('org:'.$orgA->id, $options);
        $this->assertArrayNotHasKey('org:'.$orgB->id, $options);
        $this->assertSame($orgA->id, FdaPicker::resolveTradingPartnerOrganization('org:'.$orgA->id)?->id);
        $this->assertStringContainsString('Company', $options['org:'.$orgA->id]);
    }

    #[Test]
    public function establishment_city_and_street_search_resolves_to_the_parent_org(): void
    {
        [$orgA, $orgB, $estA] = $this->seedPartnerPickerFixtures();

        $byCity = FdaPicker::tradingPartnerOrganizationOptions((string) $estA->city);
        $this->assertArrayHasKey('est:'.$estA->id, $byCity);
        $this->assertArrayNotHasKey('org:'.$orgB->id, $byCity);
        $this->assertStringContainsString((string) $estA->name, $byCity['est:'.$estA->id]);
        $this->assertStringContainsString((string) $estA->street_address, $byCity['est:'.$estA->id]);
        $this->assertStringContainsString('Plant', $byCity['est:'.$estA->id]);

        $byStreet = FdaPicker::tradingPartnerOrganizationOptions((string) $estA->street_address);
        $this->assertArrayHasKey('est:'.$estA->id, $byStreet);

        $resolved = FdaPicker::resolveTradingPartnerOrganization('est:'.$estA->id);
        $this->assertNotNull($resolved);
        $this->assertSame($orgA->id, $resolved->id);
        $this->assertNotSame($orgB->id, $resolved->id);
    }

    #[Test]
    public function wdd_name_and_address_search_resolves_to_the_parent_org_and_excludes_other_orgs_site(): void
    {
        [$orgA, $orgB, , $facA, $facB] = $this->seedPartnerPickerFixtures();

        $byName = FdaPicker::tradingPartnerOrganizationOptions((string) $facA->name);
        $this->assertArrayHasKey('wdd:'.$facA->id, $byName);
        $this->assertArrayNotHasKey('wdd:'.$facB->id, $byName);
        $this->assertStringContainsString((string) $facA->name, $byName['wdd:'.$facA->id]);
        $this->assertStringContainsString((string) $facA->street_address, $byName['wdd:'.$facA->id]);
        $this->assertStringContainsString('Warehouse', $byName['wdd:'.$facA->id]);

        $byStreet = FdaPicker::tradingPartnerOrganizationOptions((string) $facA->street_address);
        $this->assertArrayHasKey('wdd:'.$facA->id, $byStreet);
        $this->assertArrayNotHasKey('wdd:'.$facB->id, $byStreet);

        $resolved = FdaPicker::resolveTradingPartnerOrganization('wdd:'.$facA->id);
        $this->assertNotNull($resolved);
        $this->assertSame($orgA->id, $resolved->id);
        $this->assertNotSame($orgB->id, $resolved->id);
    }

    #[Test]
    public function inactive_establishment_is_excluded_from_search(): void
    {
        [$orgA] = $this->seedPartnerPickerFixtures();
        $suffix = $this->suffix();
        $inactive = FdaEstablishment::query()->create([
            'fda_organization_id' => $orgA->id,
            'name' => 'SSOR Inactive Est '.$suffix,
            'firm_name' => 'SSOR Inactive Firm '.$suffix,
            'city' => 'InactiveCity'.$suffix,
            'street_address' => '9 Inactive '.$suffix,
            'address_fingerprint' => AddressFingerprint::make('9 Inactive '.$suffix, 'InactiveCity'.$suffix, 'TX', '78701', 'US'),
            'is_active' => false,
        ]);
        $this->establishmentIds[] = (int) $inactive->id;

        $options = FdaPicker::tradingPartnerOrganizationOptions((string) $inactive->city);

        $this->assertArrayNotHasKey('est:'.$inactive->id, $options);
    }

    #[Test]
    public function prefill_after_establishment_or_wdd_pick_uses_organization_attributes(): void
    {
        [$orgA, , $estA, $facA] = $this->seedPartnerPickerFixtures();

        foreach (['est:'.$estA->id, 'wdd:'.$facA->id] as $pick) {
            $organization = FdaPicker::resolveTradingPartnerOrganization($pick);
            $this->assertNotNull($organization);

            $attrs = FdaPrefill::organizationAttributes($organization);

            $this->assertSame($orgA->id, $attrs['fda_organization_id']);
            $this->assertSame($orgA->name, $attrs['name']);
            $this->assertSame($orgA->street_address, $attrs['street_address']);
            $this->assertSame($orgA->city, $attrs['city']);
            $this->assertNotSame($estA->name, $attrs['name']);
            $this->assertNotSame($facA->name, $attrs['name']);
            $this->assertArrayNotHasKey('fda_establishment_id', $attrs);
            $this->assertArrayNotHasKey('fda_wdd_facility_id', $attrs);
        }
    }

    #[Test]
    public function create_preview_lists_a_second_site_when_the_plant_has_no_gln(): void
    {
        [$orgA, , $estA] = $this->seedPartnerPickerFixtures();

        $preview = FdaPicker::tradingPartnerCreatePreview('est:'.$estA->id, $orgA->name);

        $this->assertNotNull($preview);
        $this->assertStringContainsString('Also: '.$estA->name.' (plant)', $preview);
    }

    #[Test]
    public function create_preview_lists_a_second_site_when_the_plant_gln_differs(): void
    {
        [$orgA, , $estA] = $this->seedPartnerPickerFixtures();
        $orgA->update(['gln' => $this->uniqueGln('40')]);
        $estA->update(['gln' => $this->uniqueGln('41')]);

        $preview = FdaPicker::tradingPartnerCreatePreview('est:'.$estA->id, $orgA->name);

        $this->assertNotNull($preview);
        $this->assertStringContainsString('Also: '.$estA->name.' (plant)', $preview);
    }

    #[Test]
    public function create_preview_hides_the_extra_site_when_form_gln_matches_the_plant(): void
    {
        [$orgA, , $estA] = $this->seedPartnerPickerFixtures();
        $plantGln = $this->uniqueGln('42');
        $orgA->update(['gln' => $this->uniqueGln('43')]);
        $estA->update(['gln' => $plantGln]);

        $this->assertStringContainsString(
            'Also: '.$estA->name.' (plant)',
            (string) FdaPicker::tradingPartnerCreatePreview('est:'.$estA->id, $orgA->name, $orgA->gln),
        );
        $this->assertStringNotContainsString(
            'Also:',
            (string) FdaPicker::tradingPartnerCreatePreview('est:'.$estA->id, $orgA->name, $plantGln),
        );
    }

    #[Test]
    public function create_trading_partner_form_prefills_parent_org_from_establishment_pick(): void
    {
        [$orgA, , $estA] = $this->seedPartnerPickerFixtures();

        $this->initializeDemo2Tenant();

        try {
            $user = User::factory()->create();
            $this->userIds[] = (int) $user->id;
            $user->syncRoles([TenantRole::Owner->value]);

            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            Livewire::test(ListTradingPartners::class)
                ->mountAction('create')
                ->fillForm([
                    '_fda_pick_fda_organization_id' => 'est:'.$estA->id,
                ])
                ->assertActionDataSet([
                    'fda_organization_id' => $orgA->id,
                    'fda_pick' => 'est:'.$estA->id,
                    'name' => $orgA->name,
                    'street_address' => $orgA->street_address,
                    'city' => $orgA->city,
                ]);

            $preview = FdaPicker::tradingPartnerCreatePreview('est:'.$estA->id, $orgA->name);
            $this->assertNotNull($preview);
            $this->assertStringContainsString('Creating '.$orgA->name.' as a trading partner', $preview);
            $this->assertStringContainsString('Headquarters:', $preview);
            $this->assertStringContainsString('Also: '.$estA->name.' (plant)', $preview);
        } finally {
            if (tenancy()->initialized) {
                tenancy()->end();
            }
        }
    }

    /**
     * @return array{0: FdaOrganization, 1: FdaOrganization, 2: FdaEstablishment, 3: FdaWddFacility, 4: FdaWddFacility}
     */
    private function seedPartnerPickerFixtures(): array
    {
        $suffix = $this->suffix();

        $orgA = FdaOrganization::query()->create([
            'original_name' => 'SSOR Tp Org A '.$suffix,
            'canonical_name' => CompanyNameNormalizer::canonical('SSOR Tp Org A '.$suffix),
            'name' => 'SSOR Tp Org A '.$suffix,
            'partner_type' => PartnerType::Wholesaler,
            'street_address' => '100 Tpora '.$suffix,
            'city' => 'OrgCityA'.$suffix,
            'state_province' => 'TX',
            'postal_code' => '7'.$suffix,
            'country_code' => 'US',
            'full_address' => '100 Tpora '.$suffix.', OrgCityA'.$suffix.', TX',
            'is_active' => true,
        ]);
        $orgB = FdaOrganization::query()->create([
            'original_name' => 'SSOR Tp Org B '.$suffix,
            'canonical_name' => CompanyNameNormalizer::canonical('SSOR Tp Org B '.$suffix),
            'name' => 'SSOR Tp Org B '.$suffix,
            'partner_type' => PartnerType::Wholesaler,
            'street_address' => '200 Tporb '.$suffix,
            'city' => 'OrgCityB'.$suffix,
            'state_province' => 'TX',
            'postal_code' => '6'.$suffix,
            'country_code' => 'US',
            'full_address' => '200 Tporb '.$suffix.', OrgCityB'.$suffix.', TX',
            'is_active' => true,
        ]);
        $this->orgIds[] = (int) $orgA->id;
        $this->orgIds[] = (int) $orgB->id;

        $estA = FdaEstablishment::query()->create([
            'fda_organization_id' => $orgA->id,
            'name' => 'SSOR Tp Est A '.$suffix,
            'firm_name' => 'SSOR Tp Est Firm A '.$suffix,
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

        $facA = $this->facility($orgA, 'SSOR Tp Hub A '.$suffix, 'HubCityA'.$suffix, '11 Wddst '.$suffix);
        $facB = $this->facility($orgB, 'SSOR Tp Hub B '.$suffix, 'HubCityB'.$suffix, '22 Wddst '.$suffix);

        return [$orgA, $orgB, $estA, $facA, $facB];
    }

    private function facility(FdaOrganization $org, string $name, string $city, string $street): FdaWddFacility
    {
        $facility = FdaWddFacility::query()->create([
            'fda_organization_id' => $org->id,
            'facility_type' => FacilityType::Wdd,
            'name' => $name,
            'facility_name' => $name,
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
