<?php

namespace Tests\Feature;

use App\Enums\AtpLicenseExpirationStatus;
use App\Enums\FacilityType;
use App\Enums\PartnerType;
use App\Enums\SiteAtpReadinessStatus;
use App\Enums\TenantProfile;
use App\Filament\App\Resources\Sites\Pages\ViewSite;
use App\Filament\App\Resources\Sites\RelationManagers\AtpLicensesRelationManager;
use App\Filament\App\Resources\Sites\RelationManagers\LocationDevicesRelationManager;
use App\Models\AtpLicense;
use App\Models\LocationDevice;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Support\MasterData\SiteAtpReadiness;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SiteAtpLicensesRelationManagerTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $tenantPartnerIds = [];

    /** @var list<int> */
    private array $tenantSiteIds = [];

    /** @var list<int> */
    private array $tenantDeviceIds = [];

    #[Test]
    public function expiration_status_reports_active_expiring_expired_and_unknown_expiry(): void
    {
        $active = new AtpLicense([
            'license_expiration_date' => now()->addYear(),
        ]);
        $expiring = new AtpLicense([
            'license_expiration_date' => now()->addDays(30),
        ]);
        $expiresToday = new AtpLicense([
            'license_expiration_date' => now(),
        ]);
        $expired = new AtpLicense([
            'license_expiration_date' => now()->subDay(),
        ]);
        $noDate = new AtpLicense([
            'license_expiration_date' => null,
        ]);

        $this->assertSame(AtpLicenseExpirationStatus::Active, $active->expirationStatus());
        $this->assertSame(AtpLicenseExpirationStatus::Expiring, $expiring->expirationStatus());
        $this->assertSame(AtpLicenseExpirationStatus::Expiring, $expiresToday->expirationStatus());
        $this->assertSame(AtpLicenseExpirationStatus::Expired, $expired->expirationStatus());
        $this->assertSame(AtpLicenseExpirationStatus::UnknownExpiry, $noDate->expirationStatus());
    }

    #[Test]
    public function unknown_expiry_is_not_treated_as_valid_authorization(): void
    {
        $this->assertFalse(AtpLicenseExpirationStatus::UnknownExpiry->isValid());
        $this->assertFalse(AtpLicenseExpirationStatus::Expired->isValid());
        $this->assertTrue(AtpLicenseExpirationStatus::Active->isValid());
        $this->assertTrue(AtpLicenseExpirationStatus::Expiring->isValid());
        $this->assertSame('Unknown expiry', AtpLicenseExpirationStatus::UnknownExpiry->label());
    }

    #[Test]
    public function atp_relation_manager_badge_ignores_deactivated_licenses(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->setTenantReceivingState($tenant, 'IL');

        try {
            $site = $this->createSiteWithLicenses([
                ['license_state' => 'IL', 'license_expiration_date' => now()->addYear()],
                ['license_state' => 'IL', 'license_expiration_date' => now()->addYear(), 'is_active' => false],
            ])->fresh();

            $this->assertSame('1', AtpLicensesRelationManager::getBadge($site, ViewSite::class));
            $this->assertSame(1, SiteAtpReadiness::summarize($site)['relevant_total']);
        } finally {
            $this->cleanupTenantFixtures();
        }
    }

    #[Test]
    public function badge_and_readiness_filter_agree_on_lowercase_license_state(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->setTenantReceivingState($tenant, 'il');

        try {
            $site = $this->createSiteWithLicenses([
                ['license_state' => 'il', 'license_expiration_date' => now()->addYear()],
            ])->fresh();

            $this->assertSame('1', AtpLicensesRelationManager::getBadge($site, ViewSite::class));
            $this->assertSame(SiteAtpReadinessStatus::Ready, SiteAtpReadiness::summarize($site)['status']);
            $this->assertContains(
                $site->id,
                SiteAtpReadiness::applyStatusFilter(Site::query(), SiteAtpReadinessStatus::Ready)
                    ->pluck('id')
                    ->all(),
            );
        } finally {
            $this->cleanupTenantFixtures();
        }
    }

    #[Test]
    public function atp_relation_manager_badge_counts_receiving_state_licenses_when_set(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->setTenantReceivingState($tenant, 'IL');

        try {
            $site = $this->createSiteWithLicenses([
                ['license_state' => 'AK', 'license_expiration_date' => now()->addYear()],
                ['license_state' => 'IL', 'license_expiration_date' => now()->addYear()],
            ])->fresh();

            $this->assertSame('1', AtpLicensesRelationManager::getBadge($site, ViewSite::class));
        } finally {
            $this->cleanupTenantFixtures();
        }
    }

    #[Test]
    public function atp_relation_manager_badge_counts_all_licenses_when_receiving_state_unset(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->setTenantReceivingState($tenant, null);

        try {
            $site = $this->createSiteWithLicenses([
                ['license_state' => 'AK', 'license_expiration_date' => now()->addYear()],
                ['license_state' => 'IL', 'license_expiration_date' => now()->addYear()],
            ])->fresh();

            $this->assertSame('2', AtpLicensesRelationManager::getBadge($site, ViewSite::class));
        } finally {
            $this->cleanupTenantFixtures();
        }
    }

    #[Test]
    public function devices_relation_manager_badge_counts_location_devices(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $site = $this->createSiteWithLicenses([])->fresh();

            $device = LocationDevice::query()->create([
                'site_id' => $site->id,
                'name' => 'Scanner 1',
                'gln' => fake()->unique()->numerify('#############'),
            ]);
            $this->tenantDeviceIds[] = $device->id;

            $this->assertSame('1', LocationDevicesRelationManager::getBadge($site->fresh(), ViewSite::class));
        } finally {
            $this->cleanupTenantFixtures();
        }
    }

    /**
     * @param  list<array{license_state: string, license_expiration_date: Carbon|null, is_active?: bool}>  $licenses
     */
    private function createSiteWithLicenses(array $licenses): Site
    {
        $partner = TradingPartner::query()->create([
            'name' => 'ATP RM Test '.uniqid(),
            'gln' => fake()->unique()->numerify('#############'),
            'partner_type' => PartnerType::Wholesaler,
            'country_code' => 'US',
            'is_active' => true,
        ]);
        $this->tenantPartnerIds[] = $partner->id;

        $site = Site::query()->create([
            'trading_partner_id' => $partner->id,
            'is_headquarters' => true,
            'name' => 'HQ',
            'country_code' => 'US',
            'is_active' => true,
        ]);
        $this->tenantSiteIds[] = $site->id;

        foreach ($licenses as $index => $license) {
            AtpLicense::query()->create([
                'site_id' => $site->id,
                'facility_type' => FacilityType::Wdd,
                'license_number' => 'LIC-'.($index + 1),
                'license_state' => $license['license_state'],
                'license_expiration_date' => $license['license_expiration_date'],
                'reporting_year' => (int) now()->year,
                'is_active' => $license['is_active'] ?? true,
            ]);
        }

        return $site;
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

    private function cleanupTenantFixtures(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->tenantDeviceIds !== []) {
            LocationDevice::query()->whereIn('id', $this->tenantDeviceIds)->delete();
        }

        if ($this->tenantSiteIds !== []) {
            AtpLicense::query()->whereIn('site_id', $this->tenantSiteIds)->delete();
            LocationDevice::query()->whereIn('site_id', $this->tenantSiteIds)->delete();
            Site::query()->whereIn('id', $this->tenantSiteIds)->delete();
        }

        if ($this->tenantPartnerIds !== []) {
            TradingPartner::query()->whereIn('id', $this->tenantPartnerIds)->delete();
        }

        tenancy()->end();

        $this->tenantPartnerIds = [];
        $this->tenantSiteIds = [];
        $this->tenantDeviceIds = [];
    }
}
