<?php

namespace Tests\Feature;

use App\Enums\FacilityType;
use App\Enums\PartnerType;
use App\Enums\SiteAtpReadinessStatus;
use App\Enums\TenantProfile;
use App\Models\AtpLicense;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Support\MasterData\SiteAtpReadiness;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SiteAtpReadinessTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $tenantPartnerIds = [];

    /** @var list<int> */
    private array $tenantSiteIds = [];

    #[Test]
    public function summarize_is_ready_for_tenant_receiving_state_when_that_state_license_is_valid(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->setTenantReceivingState($tenant, 'IL');

        try {
            $site = $this->createSiteWithLicenses([
                ['license_state' => 'AK', 'license_expiration_date' => now()->subDay()],
                ['license_state' => 'IL', 'license_expiration_date' => now()->addYear()],
            ]);

            $stats = SiteAtpReadiness::summarize($site->fresh());

            $this->assertSame(SiteAtpReadinessStatus::Ready, $stats['status']);
            $this->assertSame(2, $stats['total']);
            $this->assertSame(1, $stats['relevant_total']);
            $this->assertSame('IL', $stats['tenant_state']);
            $this->assertSame(1, $stats['expired_total']);
            $this->assertSame(0, $stats['relevant_expired']);
        } finally {
            $this->cleanupTenantFixtures();
        }
    }

    #[Test]
    public function summarize_is_expired_for_tenant_receiving_state_when_that_state_license_is_expired(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->setTenantReceivingState($tenant, 'AK');

        try {
            $site = $this->createSiteWithLicenses([
                ['license_state' => 'AK', 'license_expiration_date' => now()->subDay()],
                ['license_state' => 'IL', 'license_expiration_date' => now()->addYear()],
            ]);

            $stats = SiteAtpReadiness::summarize($site->fresh());

            $this->assertSame(SiteAtpReadinessStatus::Expired, $stats['status']);
            $this->assertSame(1, $stats['relevant_total']);
            $this->assertSame(1, $stats['relevant_expired']);
            $this->assertSame('AK', $stats['tenant_state']);
        } finally {
            $this->cleanupTenantFixtures();
        }
    }

    #[Test]
    public function summarize_reports_no_licenses_when_tenant_state_has_no_matching_license(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->setTenantReceivingState($tenant, 'WY');

        try {
            $site = $this->createSiteWithLicenses([
                ['license_state' => 'AK', 'license_expiration_date' => now()->subDay()],
                ['license_state' => 'IL', 'license_expiration_date' => now()->addYear()],
            ]);

            $stats = SiteAtpReadiness::summarize($site->fresh());

            $this->assertSame(SiteAtpReadinessStatus::NoLicenses, $stats['status']);
            $this->assertSame(0, $stats['relevant_total']);
            $this->assertSame(2, $stats['total']);
            $this->assertSame('WY', $stats['tenant_state']);
        } finally {
            $this->cleanupTenantFixtures();
        }
    }

    #[Test]
    public function summarize_needs_receiving_state_when_tenant_state_is_not_set(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->setTenantReceivingState($tenant, null);

        try {
            $site = $this->createSiteWithLicenses([
                ['license_state' => 'AK', 'license_expiration_date' => now()->subDay()],
                ['license_state' => 'IL', 'license_expiration_date' => now()->addYear()],
            ]);

            $stats = SiteAtpReadiness::summarize($site->fresh());

            $this->assertSame(SiteAtpReadinessStatus::NeedsReceivingState, $stats['status']);
            $this->assertNull($stats['tenant_state']);
            $this->assertSame(2, $stats['total']);
            $this->assertSame(1, $stats['expired_total']);
            $this->assertSame(0, $stats['relevant_total']);
        } finally {
            $this->cleanupTenantFixtures();
        }
    }

    #[Test]
    public function badge_label_shows_count_and_state_when_receiving_state_is_set(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->setTenantReceivingState($tenant, 'IL');

        try {
            $site = $this->createSiteWithLicenses([
                ['license_state' => 'AK', 'license_expiration_date' => now()->subDay()],
                ['license_state' => 'IL', 'license_expiration_date' => now()->addYear()],
            ]);

            $this->assertSame('1 · IL', SiteAtpReadiness::badgeLabel($site->fresh()));
        } finally {
            $this->cleanupTenantFixtures();
        }
    }

    #[Test]
    public function badge_label_shows_total_count_when_receiving_state_is_not_set(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->setTenantReceivingState($tenant, null);

        try {
            $site = $this->createSiteWithLicenses([
                ['license_state' => 'AK', 'license_expiration_date' => now()->subDay()],
                ['license_state' => 'IL', 'license_expiration_date' => now()->addYear()],
            ]);

            $this->assertSame('2', SiteAtpReadiness::badgeLabel($site->fresh()));
        } finally {
            $this->cleanupTenantFixtures();
        }
    }

    #[Test]
    public function relevant_and_other_state_licenses_split_by_tenant_receiving_state(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->setTenantReceivingState($tenant, 'IL');

        try {
            $site = $this->createSiteWithLicenses([
                ['license_state' => 'AK', 'license_expiration_date' => now()->subDay()],
                ['license_state' => 'IL', 'license_expiration_date' => now()->addYear()],
            ])->fresh(['atpLicenses']);

            $relevant = SiteAtpReadiness::relevantLicenses($site);
            $other = SiteAtpReadiness::otherStateLicenses($site);

            $this->assertCount(1, $relevant);
            $this->assertSame('IL', $relevant->first()->license_state);
            $this->assertCount(1, $other);
            $this->assertSame('AK', $other->first()->license_state);
        } finally {
            $this->cleanupTenantFixtures();
        }
    }

    #[Test]
    public function other_state_licenses_returns_all_when_receiving_state_is_not_set(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->setTenantReceivingState($tenant, null);

        try {
            $site = $this->createSiteWithLicenses([
                ['license_state' => 'AK', 'license_expiration_date' => now()->subDay()],
                ['license_state' => 'IL', 'license_expiration_date' => now()->addYear()],
            ])->fresh(['atpLicenses']);

            $this->assertCount(0, SiteAtpReadiness::relevantLicenses($site));
            $this->assertCount(2, SiteAtpReadiness::otherStateLicenses($site));
        } finally {
            $this->cleanupTenantFixtures();
        }
    }

    #[Test]
    public function summarize_is_not_ready_when_the_relevant_license_has_no_expiration_date(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->setTenantReceivingState($tenant, 'IL');

        try {
            $site = $this->createSiteWithLicenses([
                ['license_state' => 'IL', 'license_expiration_date' => null],
            ]);

            $stats = SiteAtpReadiness::summarize($site->fresh());

            $this->assertSame(SiteAtpReadinessStatus::UnknownExpiry, $stats['status']);
            $this->assertSame(1, $stats['relevant_total']);
            $this->assertSame(1, $stats['relevant_unknown_expiry']);
            $this->assertSame(0, $stats['relevant_expired']);
            $this->assertSame(0, $stats['relevant_expiring_within_90_days']);
            $this->assertSame('warning', $stats['status']->badgeColor());
        } finally {
            $this->cleanupTenantFixtures();
        }
    }

    #[Test]
    public function summarize_keeps_a_valid_license_from_being_ready_while_another_expiry_is_unknown(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->setTenantReceivingState($tenant, 'IL');

        try {
            $site = $this->createSiteWithLicenses([
                ['license_state' => 'IL', 'license_expiration_date' => now()->addYears(2)],
                ['license_state' => 'IL', 'license_expiration_date' => null],
            ]);

            $stats = SiteAtpReadiness::summarize($site->fresh());

            $this->assertSame(SiteAtpReadinessStatus::UnknownExpiry, $stats['status']);
            $this->assertSame(2, $stats['relevant_total']);
            $this->assertSame(1, $stats['relevant_unknown_expiry']);
        } finally {
            $this->cleanupTenantFixtures();
        }
    }

    #[Test]
    public function summarize_ignores_deactivated_licenses(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->setTenantReceivingState($tenant, 'IL');

        try {
            $site = $this->createSiteWithLicenses([
                ['license_state' => 'IL', 'license_expiration_date' => now()->addYear(), 'is_active' => false],
            ]);

            $stats = SiteAtpReadiness::summarize($site->fresh());

            $this->assertSame(SiteAtpReadinessStatus::NoLicenses, $stats['status']);
            $this->assertSame(0, $stats['total']);
            $this->assertSame(0, $stats['relevant_total']);
        } finally {
            $this->cleanupTenantFixtures();
        }
    }

    #[Test]
    public function apply_status_filter_separates_unknown_expiry_from_ready(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->setTenantReceivingState($tenant, 'IL');

        try {
            $unknownExpiry = $this->createSiteWithLicenses([
                ['license_state' => 'IL', 'license_expiration_date' => null],
            ]);

            $ready = $this->createSiteWithLicenses([
                ['license_state' => 'IL', 'license_expiration_date' => now()->addYears(2)],
            ]);

            $unknownExpiryIds = SiteAtpReadiness::applyStatusFilter(
                Site::query(),
                SiteAtpReadinessStatus::UnknownExpiry,
            )->pluck('id')->all();

            $readyIds = SiteAtpReadiness::applyStatusFilter(
                Site::query(),
                SiteAtpReadinessStatus::Ready,
            )->pluck('id')->all();

            $this->assertContains($unknownExpiry->id, $unknownExpiryIds);
            $this->assertNotContains($ready->id, $unknownExpiryIds);

            $this->assertContains($ready->id, $readyIds);
            $this->assertNotContains($unknownExpiry->id, $readyIds);
        } finally {
            $this->cleanupTenantFixtures();
        }
    }

    #[Test]
    public function apply_status_filter_expiring_excludes_sites_with_an_expired_license(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->setTenantReceivingState($tenant, 'IL');

        try {
            $expiringOnly = $this->createSiteWithLicenses([
                ['license_state' => 'IL', 'license_expiration_date' => now()->addDays(30)],
            ]);

            $alsoExpired = $this->createSiteWithLicenses([
                ['license_state' => 'IL', 'license_expiration_date' => now()->addDays(30)],
                ['license_state' => 'IL', 'license_expiration_date' => now()->subDay()],
            ]);

            $expiringIds = SiteAtpReadiness::applyStatusFilter(
                Site::query(),
                SiteAtpReadinessStatus::Expiring,
            )->pluck('id')->all();

            $this->assertContains($expiringOnly->id, $expiringIds);
            $this->assertNotContains($alsoExpired->id, $expiringIds);
        } finally {
            $this->cleanupTenantFixtures();
        }
    }

    #[Test]
    public function apply_status_filter_ignores_deactivated_licenses(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->setTenantReceivingState($tenant, 'IL');

        try {
            $site = $this->createSiteWithLicenses([
                ['license_state' => 'IL', 'license_expiration_date' => now()->addYears(2), 'is_active' => false],
            ]);

            $readyIds = SiteAtpReadiness::applyStatusFilter(
                Site::query(),
                SiteAtpReadinessStatus::Ready,
            )->pluck('id')->all();

            $noLicenseIds = SiteAtpReadiness::applyStatusFilter(
                Site::query(),
                SiteAtpReadinessStatus::NoLicenses,
            )->pluck('id')->all();

            $this->assertNotContains($site->id, $readyIds);
            $this->assertContains($site->id, $noLicenseIds);
        } finally {
            $this->cleanupTenantFixtures();
        }
    }

    #[Test]
    public function apply_status_filter_scopes_expired_to_tenant_receiving_state(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->setTenantReceivingState($tenant, 'AK');

        try {
            $expiredAk = $this->createSiteWithLicenses([
                ['license_state' => 'AK', 'license_expiration_date' => now()->subDay()],
                ['license_state' => 'IL', 'license_expiration_date' => now()->addYear()],
            ]);

            $readyIlOnly = $this->createSiteWithLicenses([
                ['license_state' => 'IL', 'license_expiration_date' => now()->addYear()],
            ]);

            $filteredIds = SiteAtpReadiness::applyStatusFilter(
                Site::query(),
                SiteAtpReadinessStatus::Expired,
            )->pluck('id')->all();

            $this->assertContains($expiredAk->id, $filteredIds);
            $this->assertNotContains($readyIlOnly->id, $filteredIds);
        } finally {
            $this->cleanupTenantFixtures();
        }
    }

    /**
     * A table row asks for the badge label, its colour and its description. Summarizing
     * three times per row is three license queries per row, which is what the eager load
     * on the sites tables and this memo exist to avoid.
     */
    #[Test]
    public function summarize_queries_licenses_once_per_site_instance(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->setTenantReceivingState($tenant, 'IL');

        try {
            $created = $this->createSiteWithLicenses([
                ['license_state' => 'IL', 'license_expiration_date' => now()->addYear()],
            ]);

            $site = Site::query()->findOrFail($created->getKey());

            $queries = $this->countLicenseQueries(function () use ($site): void {
                SiteAtpReadiness::badgeLabel($site);
                SiteAtpReadiness::summarize($site);
                SiteAtpReadiness::summarize($site);
            });

            $this->assertSame(1, $queries);

            // Licences can change mid-request; forgetting the site reads them again.
            $recomputed = $this->countLicenseQueries(function () use ($site): void {
                SiteAtpReadiness::forget($site);
                SiteAtpReadiness::summarize($site);
            });

            $this->assertSame(1, $recomputed);
        } finally {
            SiteAtpReadiness::forget();
            $this->cleanupTenantFixtures();
        }
    }

    #[Test]
    public function summarize_reads_an_eager_loaded_site_without_querying_licenses(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->setTenantReceivingState($tenant, 'IL');

        try {
            $created = $this->createSiteWithLicenses([
                ['license_state' => 'IL', 'license_expiration_date' => now()->addYear()],
            ]);

            $site = Site::query()->with('atpLicenses')->findOrFail($created->getKey());

            $queries = $this->countLicenseQueries(function () use ($site): void {
                SiteAtpReadiness::summarize($site);
            });

            $this->assertSame(0, $queries);
            $this->assertSame(SiteAtpReadinessStatus::Ready, SiteAtpReadiness::summarize($site)['status']);
        } finally {
            SiteAtpReadiness::forget();
            $this->cleanupTenantFixtures();
        }
    }

    private function countLicenseQueries(callable $callback): int
    {
        $connection = (new AtpLicense)->getConnection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            $callback();
        } finally {
            $connection->disableQueryLog();
        }

        return collect($connection->getQueryLog())
            ->filter(fn (array $entry): bool => str_contains((string) $entry['query'], 'atp_licenses'))
            ->count();
    }

    /**
     * @param  list<array{license_state: string, license_expiration_date: ?Carbon, is_active?: bool}>  $licenses
     */
    private function createSiteWithLicenses(array $licenses): Site
    {
        $partner = TradingPartner::query()->create([
            'name' => 'ATP Test '.uniqid(),
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

        if ($this->tenantSiteIds !== []) {
            AtpLicense::query()->whereIn('site_id', $this->tenantSiteIds)->delete();
            Site::query()->whereIn('id', $this->tenantSiteIds)->delete();
        }

        if ($this->tenantPartnerIds !== []) {
            TradingPartner::query()->whereIn('id', $this->tenantPartnerIds)->delete();
        }

        tenancy()->end();

        $this->tenantPartnerIds = [];
        $this->tenantSiteIds = [];
    }
}
