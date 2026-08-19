<?php

namespace Tests\Feature\MasterData;

use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Models\User;
use App\Support\Auth\SiteAccess;
use App\Support\Gs1\Gtin;
use App\Support\Shipping\SearchShipToCustomers;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ownership of a site decides three things: who may hold the headquarters flag,
 * who may reach the site, and who may be shipped to.
 *
 * GLNs are prefixed 094411 so rows are traceable in the shared demo2 tenant.
 */
class OrganizationSiteOwnershipTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const GLN_PREFIX = '094411';

    private const NAME_PREFIX = 'Site Ownership ';

    private static bool $demo2TenantReady = false;

    private ?string $priorGln = null;

    private ?int $priorDefaultReceiveSiteId = null;

    private ?int $priorDefaultShipFromSiteId = null;

    /** @var list<int> */
    private array $priorOrganizationHeadquarterIds = [];

    /** @var list<int> */
    private array $userIds = [];

    #[Test]
    public function promoting_an_organization_facility_demotes_the_previous_organization_headquarters(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $first = $this->organizationFacility('10', 'Org A');
            $second = $this->organizationFacility('11', 'Org B');

            $first->forceFill(['is_headquarters' => true])->save();
            $this->assertTrue((bool) $first->fresh()->is_headquarters);

            $second->forceFill(['is_headquarters' => true])->save();

            $this->assertFalse((bool) $first->fresh()->is_headquarters);
            $this->assertTrue((bool) $second->fresh()->is_headquarters);

            $this->assertSame(
                1,
                Site::query()->ownedByOrganization()->where('is_headquarters', true)->count(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function each_partner_keeps_its_own_headquarters(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $organizationHq = $this->organizationFacility('20', 'Org HQ');
            $organizationHq->forceFill(['is_headquarters' => true])->save();

            $alpha = $this->partner('Alpha');
            $beta = $this->partner('Beta');

            $alphaFirst = $this->partnerSite($alpha, '21', 'Alpha First', isHeadquarters: true);
            $betaOnly = $this->partnerSite($beta, '22', 'Beta Only', isHeadquarters: true);
            $alphaSecond = $this->partnerSite($alpha, '23', 'Alpha Second', isHeadquarters: true);

            $this->assertFalse((bool) $alphaFirst->fresh()->is_headquarters);
            $this->assertTrue((bool) $alphaSecond->fresh()->is_headquarters);

            // Neither the other partner nor the organization loses its own flag.
            $this->assertTrue((bool) $betaOnly->fresh()->is_headquarters);
            $this->assertTrue((bool) $organizationHq->fresh()->is_headquarters);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function handing_a_site_to_a_partner_drops_user_assignments_and_tenant_defaults(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $site = $this->organizationFacility('30', 'Handed Over');
            $user = $this->user();
            $user->sites()->attach($site->getKey(), ['is_default' => true]);

            TenantSettings::forTenant($tenant)
                ->setDefaultReceiveSiteId((int) $site->getKey())
                ->setDefaultShipFromSiteId((int) $site->getKey());
            $tenant->save();

            $partner = $this->partner('New Owner');

            $site->update(Site::syncOrganizationFacilityFlag([
                'trading_partner_id' => $partner->getKey(),
            ]));

            $this->assertFalse((bool) $site->fresh()->is_organization_facility);
            $this->assertSame(
                0,
                DB::table('site_user')->where('site_id', $site->getKey())->count(),
            );

            $settings = TenantSettings::forTenant($tenant->fresh());
            $this->assertNull($settings->defaultReceiveSiteId());
            $this->assertNull($settings->defaultShipFromSiteId());

            $this->assertFalse(SiteAccess::canAccessSite($user, (int) $site->getKey()));
            $this->assertNotContains((int) $site->getKey(), SiteAccess::userSiteIds($user)->all());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function a_leftover_pivot_on_a_partner_site_grants_no_access(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $partner = $this->partner('Leftover Pivot');
            $partnerSite = $this->partnerSite($partner, '40', 'Partner Dock');

            $user = $this->user();
            $user->sites()->attach($partnerSite->getKey(), ['is_default' => true]);

            $this->assertSame(
                1,
                DB::table('site_user')->where('site_id', $partnerSite->getKey())->count(),
                'The pivot row survives; access is what must not.',
            );

            $this->assertFalse(SiteAccess::canAccessSite($user, (int) $partnerSite->getKey()));
            $this->assertNotContains((int) $partnerSite->getKey(), SiteAccess::userSiteIds($user)->all());
            $this->assertNotSame((int) $partnerSite->getKey(), SiteAccess::defaultSiteId($user));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function ship_to_search_skips_partner_sites_carrying_one_of_our_own_glns(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $organizationGln = $this->uniqueGln('50');
            $customerGln = $this->uniqueGln('51');

            $selfPartner = $this->partner('Mirrored Self');
            $mirroredSite = Site::query()->create([
                'trading_partner_id' => $selfPartner->getKey(),
                'name' => self::NAME_PREFIX.'Mirrored Dock',
                'gln' => $organizationGln,
                'is_active' => true,
                'is_organization_facility' => false,
                'country_code' => 'US',
            ]);

            $customer = $this->partner('Real Customer');
            $customerSite = Site::query()->create([
                'trading_partner_id' => $customer->getKey(),
                'name' => self::NAME_PREFIX.'Customer Dock',
                'gln' => $customerGln,
                'is_active' => true,
                'is_organization_facility' => false,
                'country_code' => 'US',
            ]);

            $tenant = $this->useOrganizationGln($tenant, $organizationGln);

            $siteIds = array_column(
                app(SearchShipToCustomers::class)->handle(self::NAME_PREFIX),
                'site_id',
            );

            $this->assertContains((int) $customerSite->getKey(), $siteIds);
            $this->assertNotContains((int) $mirroredSite->getKey(), $siteIds);
        } finally {
            $this->cleanup($tenant);
        }
    }

    private function organizationFacility(string $marker, string $name): Site
    {
        return Site::query()->create([
            'name' => self::NAME_PREFIX.$name,
            'gln' => $this->uniqueGln($marker),
            'is_active' => true,
            'is_organization_facility' => true,
            'country_code' => 'US',
        ]);
    }

    private function partnerSite(
        TradingPartner $partner,
        string $marker,
        string $name,
        bool $isHeadquarters = false,
    ): Site {
        return Site::query()->create([
            'trading_partner_id' => $partner->getKey(),
            'name' => self::NAME_PREFIX.$name,
            'gln' => $this->uniqueGln($marker),
            'is_active' => true,
            'is_headquarters' => $isHeadquarters,
            'is_organization_facility' => false,
            'country_code' => 'US',
        ]);
    }

    private function partner(string $name): TradingPartner
    {
        return TradingPartner::query()->create([
            'name' => self::NAME_PREFIX.$name,
            'partner_type' => PartnerType::Wholesaler,
            'country_code' => 'US',
            'is_active' => true,
        ]);
    }

    private function user(): User
    {
        $user = User::factory()->create();
        $this->userIds[] = (int) $user->getKey();

        return $user;
    }

    private function useOrganizationGln(Tenant $tenant, string $gln): Tenant
    {
        TenantSettings::forTenant($tenant)->setGln($gln);
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
        $this->priorDefaultReceiveSiteId = $settings->defaultReceiveSiteId();
        $this->priorDefaultShipFromSiteId = $settings->defaultShipFromSiteId();

        // The demo tenant's own headquarters is a legitimate casualty of these
        // tests; put the flag back so the shared database stays as we found it.
        $this->priorOrganizationHeadquarterIds = Site::query()
            ->ownedByOrganization()
            ->where('is_headquarters', true)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return $tenant;
    }

    private function cleanup(Tenant $tenant): void
    {
        if (tenancy()->initialized) {
            if ($this->userIds !== []) {
                DB::table('site_user')->whereIn('user_id', $this->userIds)->delete();
                User::query()->whereIn('id', $this->userIds)->delete();
                $this->userIds = [];
            }

            Site::query()->where('gln', 'like', self::GLN_PREFIX.'%')->delete();
            TradingPartner::query()->where('name', 'like', self::NAME_PREFIX.'%')->delete();

            if ($this->priorOrganizationHeadquarterIds !== []) {
                DB::table('sites')
                    ->whereIn('id', $this->priorOrganizationHeadquarterIds)
                    ->update(['is_headquarters' => true]);
                $this->priorOrganizationHeadquarterIds = [];
            }

            $current = $tenant->fresh() ?? $tenant;
            TenantSettings::forTenant($current)
                ->setGln($this->priorGln)
                ->setDefaultReceiveSiteId($this->priorDefaultReceiveSiteId)
                ->setDefaultShipFromSiteId($this->priorDefaultShipFromSiteId);
            $current->save();

            tenancy()->end();
        }

        $this->priorGln = null;
        $this->priorDefaultReceiveSiteId = null;
        $this->priorDefaultShipFromSiteId = null;
    }
}
