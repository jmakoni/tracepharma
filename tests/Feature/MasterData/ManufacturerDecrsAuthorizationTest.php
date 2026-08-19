<?php

namespace Tests\Feature\MasterData;

use App\Enums\PartnerType;
use App\Enums\SiteAtpReadinessStatus;
use App\Enums\TenantProfile;
use App\Models\Fda\FdaEstablishment;
use App\Models\Fda\FdaOrganization;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Support\Fda\AddressFingerprint;
use App\Support\Fda\CompanyNameNormalizer;
use App\Support\MasterData\AtpReadinessGate;
use App\Support\MasterData\ManufacturerDecrsAuthorization;
use App\Support\MasterData\SiteAtpReadiness;
use App\Support\TenantOnboarding;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ManufacturerDecrsAuthorizationTest extends TestCase
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
    private array $partnerIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    protected function tearDown(): void
    {
        SiteAtpReadiness::forget();
        $this->cleanupTenantRows();

        if ($this->establishmentIds !== []) {
            FdaEstablishment::query()->whereIn('id', $this->establishmentIds)->delete();
        }

        if ($this->orgIds !== []) {
            FdaOrganization::query()->whereIn('id', $this->orgIds)->delete();
        }

        parent::tearDown();
    }

    #[Test]
    public function matching_manufacturer_address_is_fda_registered_and_does_not_block(): void
    {
        [$org, $establishment] = $this->seedRegisteredPlant();
        $tenant = $this->initializeDemo2Tenant();
        $this->setTenantReceivingState($tenant, 'IL');

        try {
            $partner = $this->createPartner($org, PartnerType::Manufacturer);
            $site = $this->createSite($partner, [
                'street_address' => $establishment->street_address,
                'city' => $establishment->city,
                'state' => $establishment->state_province,
                'zipcode' => $establishment->postal_code,
                'country_code' => 'US',
            ]);

            $this->assertTrue(ManufacturerDecrsAuthorization::matches($site));
            $this->assertSame(
                SiteAtpReadinessStatus::FdaRegistered,
                SiteAtpReadiness::summarize($site)['status'],
            );
            $this->assertSame('Ready', SiteAtpReadiness::badgeLabel($site));
            $this->assertSame('FDA registered · all states', SiteAtpReadiness::badgeDescription($site));
            $blade = (string) file_get_contents(resource_path('views/filament/app/infolists/site-atp-readiness.blade.php'));
            $this->assertStringContainsString('SiteAtpReadinessStatus::FdaRegistered => \'badge-success\'', $blade);
            $this->assertStringContainsString('All states (FDA registration)', $blade);
            $this->assertFalse(AtpReadinessGate::blocksSite($site));
            $this->assertTrue(
                SiteAtpReadiness::applyStatusFilter(Site::query(), SiteAtpReadinessStatus::FdaRegistered)
                    ->whereKey($site->id)
                    ->exists(),
            );
            $this->assertTrue(TenantOnboarding::forTenant($tenant->fresh())->isUpstreamAtpSatisfied());
        } finally {
            $this->cleanupTenantRows();
        }
    }

    #[Test]
    public function deemed_status_does_not_require_a_receiving_state(): void
    {
        [$org, $establishment] = $this->seedRegisteredPlant();
        $tenant = $this->initializeDemo2Tenant();
        $this->setTenantReceivingState($tenant, null);

        try {
            $partner = $this->createPartner($org, PartnerType::Manufacturer);
            $site = $this->createSite($partner, [
                'street_address' => $establishment->street_address,
                'city' => $establishment->city,
                'state' => $establishment->state_province,
                'zipcode' => $establishment->postal_code,
                'country_code' => 'US',
            ]);

            $this->assertSame(
                SiteAtpReadinessStatus::FdaRegistered,
                SiteAtpReadiness::summarize($site)['status'],
            );
        } finally {
            $this->cleanupTenantRows();
        }
    }

    #[Test]
    public function other_manufacturer_address_still_needs_a_wdd_license(): void
    {
        [$org] = $this->seedRegisteredPlant();
        $tenant = $this->initializeDemo2Tenant();
        $this->setTenantReceivingState($tenant, 'IL');

        try {
            $partner = $this->createPartner($org, PartnerType::Manufacturer);
            $site = $this->createSite($partner, [
                'street_address' => '999 Other Yard',
                'city' => 'Dallas',
                'state' => 'TX',
                'zipcode' => '75201',
                'country_code' => 'US',
            ]);

            $this->assertFalse(ManufacturerDecrsAuthorization::matches($site));
            $this->assertSame(
                SiteAtpReadinessStatus::NoLicenses,
                SiteAtpReadiness::summarize($site)['status'],
            );
            $this->assertTrue(AtpReadinessGate::blocksSite($site));
        } finally {
            $this->cleanupTenantRows();
        }
    }

    #[Test]
    public function manufacturer_direct_address_is_deemed_even_without_a_same_org_decrs_row(): void
    {
        $org = $this->organization('SSOR Decrs Labeler '.$this->suffix());
        $tenant = $this->initializeDemo2Tenant();
        $this->setTenantReceivingState($tenant, 'CA');

        try {
            $partner = $this->createPartner($org, PartnerType::Manufacturer, [
                'street_address' => '1200 E Business Center Dr',
                'city' => 'Mt Prospect',
                'state' => 'IL',
                'zipcode' => '60056',
            ]);
            $site = $this->createSite($partner, [
                'street_address' => '1200 E. Business Center Drive',
                'city' => 'Mount Prospect',
                'state' => 'IL',
                'zipcode' => '60056',
                'country_code' => 'US',
            ]);

            $this->assertTrue(ManufacturerDecrsAuthorization::matches($site));
            $this->assertSame('Ready', SiteAtpReadiness::badgeLabel($site));
        } finally {
            $this->cleanupTenantRows();
        }
    }

    #[Test]
    public function wdd_stamped_manufacturer_site_is_not_deemed(): void
    {
        [$org] = $this->seedRegisteredPlant();
        $tenant = $this->initializeDemo2Tenant();
        $this->setTenantReceivingState($tenant, 'CA');

        try {
            $partner = $this->createPartner($org, PartnerType::Manufacturer, [
                'street_address' => '1200 E Business Center Dr',
                'city' => 'Mt Prospect',
                'state' => 'IL',
                'zipcode' => '60056',
            ]);
            $site = $this->createSite($partner, [
                'fda_wdd_facility_id' => 999001,
                'street_address' => '1200 E Business Center Dr',
                'city' => 'Mt Prospect',
                'state' => 'IL',
                'zipcode' => '60056',
                'country_code' => 'US',
            ]);

            $this->assertFalse(ManufacturerDecrsAuthorization::matches($site));
            $this->assertSame(
                SiteAtpReadinessStatus::NoLicenses,
                SiteAtpReadiness::summarize($site)['status'],
            );
        } finally {
            $this->cleanupTenantRows();
        }
    }

    #[Test]
    public function wholesaler_site_with_matching_fingerprint_is_not_deemed(): void
    {
        [$org, $establishment] = $this->seedRegisteredPlant();
        $tenant = $this->initializeDemo2Tenant();
        $this->setTenantReceivingState($tenant, 'IL');

        try {
            $partner = $this->createPartner($org, PartnerType::Wholesaler);
            $site = $this->createSite($partner, [
                'street_address' => $establishment->street_address,
                'city' => $establishment->city,
                'state' => $establishment->state_province,
                'zipcode' => $establishment->postal_code,
                'country_code' => 'US',
            ]);

            $this->assertFalse(ManufacturerDecrsAuthorization::matches($site));
            $this->assertSame(
                SiteAtpReadinessStatus::NoLicenses,
                SiteAtpReadiness::summarize($site)['status'],
            );
        } finally {
            $this->cleanupTenantRows();
        }
    }

    #[Test]
    public function inactive_excluded_or_expired_establishment_is_not_deemed(): void
    {
        $suffix = $this->suffix();
        $org = $this->organization('SSOR Decrs Dead '.$suffix);
        $street = '80 Dead Plant '.$suffix;
        $city = 'Austin';
        $this->establishment($org, [
            'name' => 'SSOR Decrs Dead Plant '.$suffix,
            'street_address' => $street,
            'city' => $city,
            'is_active' => false,
        ]);

        $tenant = $this->initializeDemo2Tenant();
        $this->setTenantReceivingState($tenant, 'IL');

        try {
            $partner = $this->createPartner($org, PartnerType::Manufacturer);
            $site = $this->createSite($partner, [
                'street_address' => $street,
                'city' => $city,
                'state' => 'TX',
                'zipcode' => '78701',
                'country_code' => 'US',
            ]);

            $this->assertFalse(ManufacturerDecrsAuthorization::matches($site));
        } finally {
            $this->cleanupTenantRows();
        }
    }

    #[Test]
    public function establishment_stamp_deems_even_when_street_punctuation_differs(): void
    {
        [$org, $establishment] = $this->seedRegisteredPlant();
        $tenant = $this->initializeDemo2Tenant();
        $this->setTenantReceivingState($tenant, 'IL');

        try {
            $partner = $this->createPartner($org, PartnerType::Manufacturer);
            $site = $this->createSite($partner, [
                'fda_establishment_id' => $establishment->id,
                'street_address' => $establishment->street_address.', Suite 2',
                'city' => $establishment->city,
                'state' => $establishment->state_province,
                'zipcode' => $establishment->postal_code,
                'country_code' => 'US',
            ]);

            $this->assertTrue(ManufacturerDecrsAuthorization::matches($site));
            $this->assertSame(
                SiteAtpReadinessStatus::FdaRegistered,
                SiteAtpReadiness::summarize($site)['status'],
            );
        } finally {
            $this->cleanupTenantRows();
        }
    }

    #[Test]
    public function ready_filter_still_requires_state_licenses_and_no_licenses_omits_deemed_plants(): void
    {
        [$org, $establishment] = $this->seedRegisteredPlant();
        $tenant = $this->initializeDemo2Tenant();
        $this->setTenantReceivingState($tenant, 'IL');

        try {
            $partner = $this->createPartner($org, PartnerType::Manufacturer);
            $site = $this->createSite($partner, [
                'street_address' => $establishment->street_address,
                'city' => $establishment->city,
                'state' => $establishment->state_province,
                'zipcode' => $establishment->postal_code,
                'country_code' => 'US',
            ]);

            $this->assertFalse(
                SiteAtpReadiness::applyStatusFilter(Site::query(), SiteAtpReadinessStatus::Ready)
                    ->whereKey($site->id)
                    ->exists(),
            );
            $this->assertFalse(
                SiteAtpReadiness::applyStatusFilter(Site::query(), SiteAtpReadinessStatus::NoLicenses)
                    ->whereKey($site->id)
                    ->exists(),
            );
            $this->assertTrue(
                SiteAtpReadiness::applyStatusFilter(Site::query(), SiteAtpReadinessStatus::FdaRegistered)
                    ->whereKey($site->id)
                    ->exists(),
            );
        } finally {
            $this->cleanupTenantRows();
        }
    }

    /**
     * @return array{0: FdaOrganization, 1: FdaEstablishment}
     */
    private function seedRegisteredPlant(): array
    {
        $suffix = $this->suffix();
        $org = $this->organization('SSOR Decrs Org '.$suffix);
        $establishment = $this->establishment($org, [
            'name' => 'SSOR Decrs Plant '.$suffix,
            'street_address' => '14 Decrs Way '.$suffix,
            'city' => 'Austin',
        ]);

        return [$org, $establishment];
    }

    private function organization(string $name): FdaOrganization
    {
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
     * @param  array<string, mixed>  $overrides
     */
    private function establishment(FdaOrganization $org, array $overrides): FdaEstablishment
    {
        $street = (string) ($overrides['street_address'] ?? '14 Decrs Way');
        $city = (string) ($overrides['city'] ?? 'Austin');
        $state = (string) ($overrides['state_province'] ?? 'TX');
        $zip = (string) ($overrides['postal_code'] ?? '78701');

        $establishment = FdaEstablishment::query()->create([
            'fda_organization_id' => $org->id,
            'name' => $overrides['name'] ?? 'SSOR Decrs Plant',
            'firm_name' => $overrides['name'] ?? 'SSOR Decrs Plant',
            'street_address' => $street,
            'city' => $city,
            'state_province' => $state,
            'postal_code' => $zip,
            'country_code' => 'US',
            'address_fingerprint' => AddressFingerprint::make($street, $city, $state, $zip, 'US'),
            'exclusion_flag' => $overrides['exclusion_flag'] ?? false,
            'expiration_date' => $overrides['expiration_date'] ?? null,
            'is_active' => $overrides['is_active'] ?? true,
            'is_currently_registered' => $overrides['is_currently_registered'] ?? true,
        ]);
        $this->establishmentIds[] = (int) $establishment->id;

        return $establishment;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createPartner(FdaOrganization $org, PartnerType $type, array $attributes = []): TradingPartner
    {
        $partner = TradingPartner::query()->create(array_merge([
            'fda_organization_id' => $org->id,
            'name' => $org->name,
            'gln' => fake()->unique()->numerify('#############'),
            'partner_type' => $type,
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
            Site::query()->whereIn('id', $this->siteIds)->delete();
        }

        if ($this->partnerIds !== []) {
            Site::query()->whereIn('trading_partner_id', $this->partnerIds)->delete();
            TradingPartner::query()->whereIn('id', $this->partnerIds)->delete();
        }

        $this->siteIds = [];
        $this->partnerIds = [];

        tenancy()->end();
    }

    private function suffix(): string
    {
        return Str::lower(Str::random(6));
    }
}
