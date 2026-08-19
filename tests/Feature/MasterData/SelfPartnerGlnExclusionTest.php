<?php

namespace Tests\Feature\MasterData;

use App\Actions\Epcis\EnsureCatalogPartiesFromEpcisLocations;
use App\Actions\MasterData\CreateHqSiteForTradingPartner;
use App\Actions\MasterData\DeactivateSelfTradingPartners;
use App\Actions\MasterData\EnsureManufacturerPartnerFromCatalog;
use App\Actions\MasterData\EnsureWholesalerPartnerFromCatalog;
use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\OrganizationSettings;
use App\Models\Fda\FdaOrganization;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Gs1\AssertOrganizationSsccIdentity;
use App\Support\Gs1\Gtin;
use App\Support\TenantSettings;
use Filament\Facades\Filament;
use InvalidArgumentException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tenant GLNs (organization GLN ∪ organization facility GLNs) must never become
 * trading partners or partner-owned sites, and legacy self-partners must heal.
 *
 * GLNs are prefixed 093117 so rows are traceable in the shared demo2 tenant.
 */
class SelfPartnerGlnExclusionTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const GLN_PREFIX = '093117';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $orgIds = [];

    private ?string $priorGln = null;

    private ?string $priorCompanyPrefix = null;

    #[Test]
    public function ensure_catalog_parties_skips_tenant_glns_but_still_creates_real_partners(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $organizationGln = $this->uniqueGln('10');
            $facilityGln = $this->uniqueGln('11');
            $partnerGln = $this->uniqueGln('12');
            $partnerLocationGln = $this->uniqueGln('13');

            $facility = $this->createOrganizationFacility($facilityGln, 'Self Excl Dock 093117');
            $tenant = $this->useOrganizationGln($tenant, $organizationGln);

            $stats = app(EnsureCatalogPartiesFromEpcisLocations::class)->handle([
                ['gln' => $organizationGln, 'name' => 'Self Excl Us 093117', 'country_code' => 'US'],
                ['gln' => $facilityGln, 'name' => 'Self Excl Dock 093117', 'country_code' => 'US'],
                ['gln' => $partnerGln, 'name' => 'Self Excl Supplier 093117', 'country_code' => 'US'],
                [
                    'gln' => $partnerLocationGln,
                    'name' => 'Self Excl Supplier Dock 093117',
                    'street_address' => '7 Supplier Way',
                    'country_code' => 'US',
                ],
            ], [
                'source_owning_party_gln' => $partnerGln,
                'source_location_gln' => $partnerLocationGln,
                'destination_owning_party_gln' => $organizationGln,
                'destination_location_gln' => $facilityGln,
            ]);

            $this->assertNull(
                TradingPartner::query()->where('gln', $organizationGln)->first(),
                'The organization GLN must never become a trading partner.',
            );
            $this->assertNull(
                Site::query()->where('gln', $organizationGln)->first(),
                'The organization GLN must never become a partner-owned site.',
            );
            $this->assertSame(1, $stats['tenant_partners_ensured']);

            $facility->refresh();
            $this->assertNull($facility->trading_partner_id);
            $this->assertTrue((bool) $facility->is_organization_facility);
            $this->assertNull($facility->fda_wdd_facility_id);
            $this->assertNull($facility->fda_establishment_id);

            $supplier = TradingPartner::query()->where('gln', $partnerGln)->first();
            $this->assertNotNull($supplier, 'Real inbound partners are still created.');

            $supplierSite = Site::query()->where('gln', $partnerLocationGln)->first();
            $this->assertNotNull($supplierSite);
            $this->assertSame((int) $supplier->getKey(), (int) $supplierSite->trading_partner_id);

            $this->assertNull(
                FdaOrganization::query()->where('gln', $organizationGln)->first(),
                'The tenant organization GLN must not become a shared FDA organization row.',
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function ensure_partner_from_fda_organization_returns_null_for_organization_gln(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $organizationGln = $this->uniqueGln('20');
            $tenant = $this->useOrganizationGln($tenant, $organizationGln);

            $org = FdaOrganization::query()->create([
                'original_name' => 'Self Excl FDA Us 093117',
                'canonical_name' => 'SELF EXCL FDA US 093117',
                'name' => 'Self Excl FDA Us 093117',
                'gln' => $organizationGln,
                'partner_type' => PartnerType::Wholesaler,
                'country_code' => 'US',
                'is_active' => true,
            ]);
            $this->orgIds[] = (int) $org->getKey();

            $this->assertNull(app(EnsureManufacturerPartnerFromCatalog::class)->handle($org));
            $this->assertNull(app(EnsureWholesalerPartnerFromCatalog::class)->handle($org));
            $this->assertNull(TradingPartner::query()->where('gln', $organizationGln)->first());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function create_hq_site_skips_when_gln_belongs_to_an_organization_facility(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $facilityGln = $this->uniqueGln('30');
            $facility = $this->createOrganizationFacility($facilityGln, 'Self Excl HQ Clash Dock 093117');

            $selfPartner = TradingPartner::query()->create([
                'name' => 'Self Excl Legacy Self 093117',
                'gln' => $facilityGln,
                'partner_type' => PartnerType::Wholesaler,
                'country_code' => 'US',
                'is_active' => true,
            ]);

            $this->assertNull(app(CreateHqSiteForTradingPartner::class)->handle($selfPartner));
            $this->assertSame(1, Site::query()->where('gln', $facilityGln)->count());
            $this->assertNull($facility->refresh()->trading_partner_id);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function create_hq_site_adopts_the_partner_site_that_already_holds_the_gln(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $partnerGln = $this->uniqueGln('40');

            $partner = TradingPartner::query()->create([
                'name' => 'Self Excl Adopt Partner 093117',
                'gln' => $partnerGln,
                'partner_type' => PartnerType::Wholesaler,
                'country_code' => 'US',
                'is_active' => true,
            ]);

            $partnerSite = Site::query()->create([
                'trading_partner_id' => $partner->getKey(),
                'name' => 'Self Excl Adopt Site 093117',
                'gln' => $partnerGln,
                'is_active' => true,
                'is_headquarters' => false,
                'is_organization_facility' => false,
                'country_code' => 'US',
            ]);

            $site = app(CreateHqSiteForTradingPartner::class)->handle($partner);

            $this->assertNotNull($site);
            $this->assertTrue($partnerSite->is($site));
            $this->assertTrue((bool) $partnerSite->refresh()->is_headquarters);
            $this->assertSame(1, Site::query()->where('gln', $partnerGln)->count());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function sscc_identity_ignores_self_partners_and_inactive_partners(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $facilityGln = $this->uniqueGln('50');
            $inactiveGln = $this->uniqueGln('51');

            $this->createOrganizationFacility($facilityGln, 'Self Excl Identity Dock 093117');

            TradingPartner::query()->create([
                'name' => 'Self Excl Identity Self 093117',
                'gln' => $facilityGln,
                'partner_type' => PartnerType::Wholesaler,
                'country_code' => 'US',
                'is_active' => true,
            ]);

            $inactive = TradingPartner::query()->create([
                'name' => 'Self Excl Identity Inactive 093117',
                'gln' => $inactiveGln,
                'partner_type' => PartnerType::Wholesaler,
                'country_code' => 'US',
                'is_active' => false,
            ]);

            app(AssertOrganizationSsccIdentity::class)->handle($facilityGln, null);
            app(AssertOrganizationSsccIdentity::class)->handle($inactiveGln, null);
            $this->addToAssertionCount(2);

            $inactive->forceFill(['is_active' => true])->save();

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Organization GLN matches trading partner');

            app(AssertOrganizationSsccIdentity::class)->handle($inactiveGln, null);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function heal_command_deactivates_self_partners_and_returns_the_organization_gln(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $organizationGln = $this->uniqueGln('60');
            $tenant = $this->useOrganizationGln($tenant, $organizationGln);

            $selfPartner = TradingPartner::query()->create([
                'name' => 'Self Excl Heal Me 093117',
                'gln' => $organizationGln,
                'partner_type' => PartnerType::Pharmacy,
                'country_code' => 'US',
                'is_active' => true,
            ]);

            $mirroredDock = Site::query()->create([
                'trading_partner_id' => $selfPartner->getKey(),
                'name' => 'Self Excl Heal Dock 093117',
                'gln' => $organizationGln,
                'is_active' => true,
                'is_organization_facility' => false,
                'country_code' => 'US',
            ]);

            $stats = app(DeactivateSelfTradingPartners::class)->handle();

            // Counts are tenant-wide: the shared demo tenant may carry other
            // self-partners this heal legitimately picks up.
            $this->assertGreaterThanOrEqual(1, $stats['partners_deactivated']);
            $this->assertGreaterThanOrEqual(1, $stats['partners_renamed']);
            $this->assertSame(1, $stats['sites_promoted']);

            $selfPartner->refresh();
            $this->assertFalse((bool) $selfPartner->is_active);
            $this->assertStringStartsWith(
                DeactivateSelfTradingPartners::NAME_PREFIX,
                (string) $selfPartner->name,
            );

            $mirroredDock->refresh();
            $this->assertNull($mirroredDock->trading_partner_id);
            $this->assertTrue((bool) $mirroredDock->is_organization_facility);

            app(AssertOrganizationSsccIdentity::class)->handle(
                $organizationGln,
                substr($organizationGln, 0, 7),
            );
            $this->addToAssertionCount(1);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function organization_settings_reports_identity_conflict_as_a_form_error(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $partnerGln = $this->uniqueGln('70');

            TradingPartner::query()->create([
                'name' => 'Self Excl Settings Supplier 093117',
                'gln' => $partnerGln,
                'partner_type' => PartnerType::Wholesaler,
                'country_code' => 'US',
                'is_active' => true,
            ]);

            $this->actingAs($this->createOwner());
            Filament::setCurrentPanel(Filament::getPanel('app'));

            Livewire::test(OrganizationSettings::class)
                ->fillForm([
                    'gln' => $partnerGln,
                    'company_prefix' => null,
                    'default_receive_site_id' => null,
                    'default_ship_from_site_id' => null,
                ])
                ->call('save')
                ->assertHasFormErrors(['gln']);

            $this->assertNotSame(
                $partnerGln,
                TenantSettings::forTenant($tenant->fresh())->gln(),
                'A rejected identity must not persist.',
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    private function createOwner(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        $user = User::factory()->create();
        $user->assignRole(TenantRole::Owner->value);

        return $user;
    }

    private function createOrganizationFacility(string $gln, string $name): Site
    {
        return Site::query()->create([
            'name' => $name,
            'gln' => $gln,
            'is_active' => true,
            'is_organization_facility' => true,
            'country_code' => 'US',
        ]);
    }

    private function useOrganizationGln(Tenant $tenant, string $gln): Tenant
    {
        TenantSettings::forTenant($tenant)
            ->setGln($gln)
            ->setCompanyPrefix(substr($gln, 0, 7));
        $tenant->save();

        tenancy()->end();
        $fresh = $tenant->fresh();
        tenancy()->initialize($fresh);

        return $fresh;
    }

    private function uniqueGln(string $marker): string
    {
        $body12 = self::GLN_PREFIX.$marker.str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        return $body12.Gtin::checkDigit($body12);
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

        $settings = TenantSettings::forTenant($tenant);
        $this->priorGln = $settings->gln();
        $this->priorCompanyPrefix = $settings->companyPrefix();

        return $tenant;
    }

    private function cleanup(Tenant $tenant): void
    {
        if (tenancy()->initialized) {
            Site::query()
                ->where('gln', 'like', self::GLN_PREFIX.'%')
                ->orWhere('name', 'like', 'Self Excl%')
                ->delete();
            TradingPartner::query()
                ->where('gln', 'like', self::GLN_PREFIX.'%')
                ->orWhere('name', 'like', 'Self Excl%')
                ->orWhere('name', 'like', '[SELF] Self Excl%')
                ->delete();

            $current = $tenant->fresh() ?? $tenant;
            TenantSettings::forTenant($current)
                ->setGln($this->priorGln)
                ->setCompanyPrefix($this->priorCompanyPrefix);
            $current->save();

            tenancy()->end();
        }

        if ($this->orgIds !== []) {
            FdaOrganization::query()->whereIn('id', $this->orgIds)->delete();
            $this->orgIds = [];
        }

        $this->priorGln = null;
        $this->priorCompanyPrefix = null;
    }
}
