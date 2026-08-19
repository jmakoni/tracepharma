<?php

namespace Tests\Feature\MasterData;

use App\Enums\FacilityType;
use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Filament\App\Resources\Sites\Pages\ListSites;
use App\Filament\App\Resources\Sites\Tables\SitesTable;
use App\Models\AtpLicense;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Renewals are the tenant's job and they are due whatever state issued the licence,
 * so the Sites list has to answer "what expires next" without a receiving state set.
 */
class SitesExpiringLicenseFilterTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $partnerIds = [];

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    #[Test]
    public function the_filter_keeps_only_sites_with_a_license_expiring_within_90_days(): void
    {
        $this->initializeDemo2Tenant();

        $expiringSoon = $this->siteWithLicense(now()->addDays(30));
        $edge = $this->siteWithLicense(now()->addDays(90));
        $later = $this->siteWithLicense(now()->addDays(120));
        $expired = $this->siteWithLicense(now()->subDay());
        $unknown = $this->siteWithLicense(null);
        $deactivated = $this->siteWithLicense(now()->addDays(10), isActive: false);

        $matched = $this->filteredSiteIds();

        $this->assertContains($expiringSoon->id, $matched);
        $this->assertContains($edge->id, $matched);
        $this->assertNotContains($later->id, $matched);
        $this->assertNotContains($expired->id, $matched);
        $this->assertNotContains($unknown->id, $matched);
        $this->assertNotContains(
            $deactivated->id,
            $matched,
            'A delisted licence does not need renewing.',
        );
    }

    /**
     * @return list<int>
     */
    private function filteredSiteIds(): array
    {
        $query = Site::query()->whereIn('id', $this->siteIds);

        $this->filter()->apply($query, ['isActive' => true]);

        return $query->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    private function filter(): BaseFilter
    {
        $filter = collect(SitesTable::configure(Table::make(new ListSites))->getFilters())
            ->first(fn (BaseFilter $filter): bool => $filter->getName() === 'atp_expiring_90_days');

        $this->assertNotNull($filter, 'The sites table is missing the expiring-license filter.');

        return $filter;
    }

    private function siteWithLicense(?Carbon $expiration, bool $isActive = true): Site
    {
        $partner = TradingPartner::query()->create([
            'name' => 'Expiring Filter Partner '.uniqid(),
            'gln' => fake()->unique()->numerify('#############'),
            'partner_type' => PartnerType::Wholesaler,
            'country_code' => 'US',
            'is_active' => true,
        ]);
        $this->partnerIds[] = (int) $partner->id;

        $site = Site::query()->create([
            'trading_partner_id' => $partner->id,
            'name' => 'Expiring Filter Site '.uniqid(),
            'country_code' => 'US',
            'is_active' => true,
            'is_organization_facility' => false,
        ]);
        $this->siteIds[] = (int) $site->id;

        AtpLicense::query()->create([
            'site_id' => $site->id,
            'facility_type' => FacilityType::Wdd,
            'license_number' => 'EXP-FILTER-'.uniqid(),
            'license_state' => 'NY',
            'license_expiration_date' => $expiration?->toDateString(),
            'reporting_year' => (int) now()->year,
            'is_active' => $isActive,
        ]);

        return $site;
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

    private function cleanup(): void
    {
        if (tenancy()->initialized) {
            if ($this->siteIds !== []) {
                AtpLicense::query()->whereIn('site_id', $this->siteIds)->delete();
                Site::query()->whereIn('id', $this->siteIds)->delete();
            }
            if ($this->partnerIds !== []) {
                TradingPartner::query()->whereIn('id', $this->partnerIds)->delete();
            }
            tenancy()->end();
        }

        $this->siteIds = [];
        $this->partnerIds = [];
    }
}
