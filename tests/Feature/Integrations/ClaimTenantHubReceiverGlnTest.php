<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Actions\Integrations\ClaimTenantHubReceiverGln;
use App\Actions\Integrations\RegisterEpcisHubRoute;
use App\Enums\InboundTransport;
use App\Enums\SerializationProvider;
use App\Enums\TenantProfile;
use App\Models\EpcisHubRoute;
use App\Models\InboundConnection;
use App\Models\Site;
use App\Models\Tenant;
use App\Support\EpcisHub\ClaimableReceiverGlns;
use App\Support\EpcisHub\EpcisHubPlatformConfig;
use App\Support\Gs1\Gtin;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class ClaimTenantHubReceiverGlnTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const DEMO2_GLN = '0366159000010';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $routeIds = [];

    /** @var list<string> */
    private array $orphanTenantIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $connectionIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        app(EpcisHubPlatformConfig::class)->setProviders('stage', ['systech', 'unitrace']);
    }

    protected function tearDown(): void
    {
        if ($this->routeIds !== []) {
            EpcisHubRoute::query()->whereIn('id', $this->routeIds)->delete();
        }

        EpcisHubRoute::query()->whereIn('tenant_id', $this->orphanTenantIds)->delete();

        foreach ($this->orphanTenantIds as $tenantId) {
            Tenant::withoutEvents(fn () => Tenant::query()->whereKey($tenantId)->delete());
        }

        if (tenancy()->initialized) {
            foreach ($this->connectionIds as $connectionId) {
                InboundConnection::query()->whereKey($connectionId)->delete();
            }
            foreach ($this->siteIds as $siteId) {
                Site::query()->whereKey($siteId)->delete();
            }
            tenancy()->end();
        }

        parent::tearDown();
    }

    #[Test]
    public function admin_can_claim_company_and_site_gln(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $tenant->forceFill([
            'gln' => self::DEMO2_GLN,
            'inbound_environment' => 'stage',
            'hub_providers' => ['systech'],
        ])->save();

        $siteGln = $this->createOrgFacilityGln($tenant);

        $companyRoute = app(ClaimTenantHubReceiverGln::class)->claim($tenant, 'systech', self::DEMO2_GLN);
        $this->routeIds[] = (int) $companyRoute->id;
        $this->assertSame(ClaimTenantHubReceiverGln::VIA_ADMIN, $companyRoute->claimed_via);
        $this->assertTrue($companyRoute->is_active);

        $siteRoute = app(ClaimTenantHubReceiverGln::class)->claim($tenant, 'systech', $siteGln);
        $this->routeIds[] = (int) $siteRoute->id;
        $this->assertSame($siteGln, $siteRoute->gln);

        $options = ClaimableReceiverGlns::options($tenant);
        $this->assertArrayHasKey(self::DEMO2_GLN, $options);
        $this->assertArrayHasKey($siteGln, $options);
    }

    #[Test]
    public function claim_rejects_orphan_gln_unless_allow_orphan(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $tenant->forceFill([
            'gln' => self::DEMO2_GLN,
            'inbound_environment' => 'stage',
            'hub_providers' => ['systech'],
        ])->save();

        $body = '061414199999';
        $orphan = $body.Gtin::checkDigit($body);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not the company GLN');

        app(ClaimTenantHubReceiverGln::class)->claim($tenant, 'systech', $orphan);
    }

    #[Test]
    public function claim_blocks_cross_tenant_conflict_in_same_environment(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $tenant->forceFill([
            'gln' => self::DEMO2_GLN,
            'inbound_environment' => 'stage',
            'hub_providers' => ['systech'],
        ])->save();

        $siteGln = $this->createOrgFacilityGln($tenant);
        $route = app(ClaimTenantHubReceiverGln::class)->claim($tenant, 'systech', $siteGln);
        $this->routeIds[] = (int) $route->id;

        $other = Tenant::withoutEvents(fn () => Tenant::query()->create([
            'id' => (string) str()->uuid(),
            'name' => 'Other claimer',
            'profile' => TenantProfile::Pharmacy,
            'status' => 'active',
            'gln' => self::DEMO2_GLN,
            'inbound_environment' => 'stage',
            'hub_providers' => ['systech'],
            'tenancy_db_name' => 'tenant_claim_conflict_test',
        ]));
        $this->orphanTenantIds[] = (string) $other->id;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already claimed');

        app(ClaimTenantHubReceiverGln::class)->claim($other, 'systech', $siteGln, allowOrphan: true);
    }

    #[Test]
    public function register_binds_admin_claims_and_unregister_preserves_them(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $tenant->forceFill([
            'gln' => self::DEMO2_GLN,
            'inbound_environment' => 'stage',
            'hub_providers' => ['systech'],
        ])->save();

        $siteGln = $this->createOrgFacilityGln($tenant);
        $claim = app(ClaimTenantHubReceiverGln::class)->claim($tenant, 'systech', $siteGln);
        $this->routeIds[] = (int) $claim->id;

        $connection = $tenant->run(fn (): InboundConnection => InboundConnection::query()->create([
            'name' => 'Bind admin claim',
            'serialization_provider' => SerializationProvider::Systech,
            'transport' => InboundTransport::Https,
            'is_active' => true,
        ]));
        $this->connectionIds[] = (int) $connection->getKey();

        $bound = $tenant->run(fn (): EpcisHubRoute => app(RegisterEpcisHubRoute::class)->register($connection));
        $this->assertSame($siteGln, $bound->gln);
        $this->assertSame((int) $connection->getKey(), (int) $bound->default_inbound_connection_id);
        $this->assertSame(ClaimTenantHubReceiverGln::VIA_ADMIN, $bound->claimed_via);

        $tenant->run(fn () => app(RegisterEpcisHubRoute::class)->unregister($connection));

        $claim->refresh();
        $this->assertNull($claim->default_inbound_connection_id);
        $this->assertTrue(
            EpcisHubRoute::query()->whereKey($claim->id)->exists(),
            'Admin claim must survive connection unregister',
        );
    }

    private function createOrgFacilityGln(Tenant $tenant): string
    {
        $body = '888840'.str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT).'0';
        $body = substr($body, 0, 12);
        $gln = $body.Gtin::checkDigit($body);

        $site = $tenant->run(fn (): Site => Site::query()->create([
            'name' => 'Hub claim facility',
            'code' => 'HUB-CLAIM-'.strtoupper(substr(md5((string) microtime(true)), 0, 6)),
            'gln' => $gln,
            'is_organization_facility' => true,
            'trading_partner_id' => null,
            'is_active' => true,
        ]));
        $this->siteIds[] = (int) $site->getKey();

        return $gln;
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
                'gln' => self::DEMO2_GLN,
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

        return $tenant->fresh();
    }
}
