<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Actions\Integrations\ClaimTenantHubReceiverGln;
use App\Enums\ConnectionApprovalStatus;
use App\Enums\InboundTransport;
use App\Enums\PartnerType;
use App\Enums\SerializationProvider;
use App\Enums\TenantProfile;
use App\Models\EpcisHubRoute;
use App\Models\InboundConnection;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Services\Epcis\Hub\EpcisHubRouter;
use App\Support\EpcisHub\EpcisHubPlatformConfig;
use App\Support\PlatformSettings;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class HubRouteDirectoryTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $routeIds = [];

    /** @var list<int> */
    private array $inboundConnectionIds = [];

    /** @var list<int> */
    private array $partnerIds = [];

    /** @var array{inbound_environment: mixed, hub_providers: mixed}|null */
    private ?array $originalEntitlement = null;

    protected function tearDown(): void
    {
        $this->restoreEntitlement();
        $this->forgetHubSettings();
        $this->cleanup();

        parent::tearDown();
    }

    #[Test]
    public function conflict_guard_blocks_two_tenants_claiming_the_same_gln(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $other = $this->makeSecondTenant();

        $this->entitle($tenant, ['tracepharma']);
        $this->entitle($other, ['tracepharma']);
        $this->enablePlatformProviders('demo', ['tracepharma']);

        $claim = app(ClaimTenantHubReceiverGln::class);
        $route = $claim->claim($other, 'tracepharma', '0399999000016', allowOrphan: true);
        $this->routeIds[] = (int) $route->getKey();

        try {
            $claim->claim($tenant, 'tracepharma', '0399999000016', allowOrphan: true);
            $this->fail('Expected a conflict RuntimeException.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('already claimed', $exception->getMessage());
            $this->assertStringContainsString($other->name, $exception->getMessage());
        }
    }

    #[Test]
    public function describe_resolution_walks_the_chain_to_a_connection(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->entitle($tenant, ['tracepharma']);
        $this->enablePlatformProviders('demo', ['tracepharma']);

        $connection = $this->makeHubInboundConnection();

        $route = app(ClaimTenantHubReceiverGln::class)
            ->claim($tenant, 'tracepharma', '0399999000023', allowOrphan: true);
        $route->forceFill(['default_inbound_connection_id' => $connection->getKey()])->save();
        $this->routeIds[] = (int) $route->getKey();

        $steps = app(EpcisHubRouter::class)->describeResolution(
            'tracepharma',
            '0399999000023',
            null,
            'demo',
        );

        $this->assertCount(4, $steps);
        $this->assertTrue(collect($steps)->every(fn (array $step): bool => $step['ok']));
        $this->assertStringContainsString($connection->name, $steps[3]['detail']);
    }

    #[Test]
    public function describe_resolution_pinpoints_the_failing_step(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->entitle($tenant, ['tracepharma']);
        $this->enablePlatformProviders('demo', ['tracepharma']);

        // No route exists for this GLN: the chain must stop at receiver resolution.
        $steps = app(EpcisHubRouter::class)->describeResolution(
            'tracepharma',
            '0399999000993',
            null,
            'demo',
        );

        $this->assertCount(2, $steps);
        $this->assertTrue($steps[0]['ok']);
        $this->assertFalse($steps[1]['ok']);
        $this->assertStringContainsString('No tenant is registered', $steps[1]['detail']);

        // Provider disabled: the chain stops at step one.
        $steps = app(EpcisHubRouter::class)->describeResolution(
            'ghostnet',
            '0399999000023',
            null,
            'demo',
        );

        $this->assertCount(1, $steps);
        $this->assertFalse($steps[0]['ok']);
    }

    #[Test]
    public function deactivated_route_is_skipped_by_tenant_resolution(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->entitle($tenant, ['tracepharma']);
        $this->enablePlatformProviders('demo', ['tracepharma']);

        $route = app(ClaimTenantHubReceiverGln::class)
            ->claim($tenant, 'tracepharma', '0399999000030', allowOrphan: true);
        $this->routeIds[] = (int) $route->getKey();

        $route->forceFill(['is_active' => false])->save();

        $steps = app(EpcisHubRouter::class)->describeResolution(
            'tracepharma',
            '0399999000030',
            null,
            'demo',
        );

        $this->assertFalse($steps[1]['ok']);
        $this->assertStringContainsString('No tenant is registered', $steps[1]['detail']);
    }

    #[Test]
    public function successful_resolution_stamps_last_routed_at(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->entitle($tenant, ['tracepharma']);
        $this->enablePlatformProviders('demo', ['tracepharma']);

        $connection = $this->makeHubInboundConnection();

        // The fixture sends from 0301160000009; register it so sender matching succeeds.
        $partner = TradingPartner::query()->where('gln', '0301160000009')->first();

        if ($partner === null) {
            $partner = TradingPartner::query()->create([
                'name' => 'Hub Sender '.Str::random(4),
                'gln' => '0301160000009',
                'partner_type' => PartnerType::Pharmacy,
                'country_code' => 'US',
                'is_active' => true,
            ]);
            $this->partnerIds[] = (int) $partner->getKey();
        }

        $connection->forceFill(['trading_partner_id' => $partner->getKey()])->save();

        $route = app(ClaimTenantHubReceiverGln::class)
            ->claim($tenant, 'tracepharma', '0399999000047', allowOrphan: true);
        $route->forceFill(['default_inbound_connection_id' => $connection->getKey()])->save();
        $this->routeIds[] = (int) $route->getKey();

        $this->assertNull($route->last_routed_at);

        $xml = file_get_contents(base_path('tests/Fixtures/epcis/minimal_object_shipping.xml'));
        $this->assertNotFalse($xml);
        $xml = preg_replace(
            '/(<sbdh:Receiver>.*?<sbdh:Identifier[^>]*>)[^<]+(<\/sbdh:Identifier>.*?<\/sbdh:Receiver>)/s',
            '${1}0399999000047${2}',
            (string) $xml,
        );

        $resolution = app(EpcisHubRouter::class)->resolve('tracepharma', (string) $xml, 'demo');

        $this->assertSame($tenant->getKey(), $resolution->tenant->getKey());
        $this->assertNotNull($route->fresh()->last_routed_at);
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

    private function makeSecondTenant(): Tenant
    {
        $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Conflict Tenant '.Str::random(4),
            'profile' => TenantProfile::Pharmacy,
            'status' => 'active',
            'tenancy_db_name' => self::DEMO2_DATABASE,
        ]));

        return $tenant;
    }

    private function makeHubInboundConnection(): InboundConnection
    {
        $connection = InboundConnection::query()->create([
            'name' => 'Hub directory test '.Str::random(6),
            'serialization_provider' => SerializationProvider::TracePharma,
            'transport' => InboundTransport::Https,
            'is_active' => true,
            'approval_status' => ConnectionApprovalStatus::Approved,
        ]);

        $this->inboundConnectionIds[] = (int) $connection->getKey();

        return $connection;
    }

    /**
     * @param  list<string>  $providers
     */
    private function entitle(Tenant $tenant, array $providers): void
    {
        if ($tenant->getKey() === self::DEMO2_TENANT_ID && $this->originalEntitlement === null) {
            $this->originalEntitlement = [
                'inbound_environment' => $tenant->inbound_environment,
                'hub_providers' => $tenant->hub_providers,
            ];
        }

        $tenant->forceFill([
            'inbound_environment' => 'demo',
            'hub_providers' => $providers,
        ])->save();
    }

    private function restoreEntitlement(): void
    {
        if ($this->originalEntitlement === null) {
            return;
        }

        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant !== null) {
            $tenant->forceFill($this->originalEntitlement)->save();
        }

        $this->originalEntitlement = null;
    }

    /**
     * @param  list<string>  $providers
     */
    private function enablePlatformProviders(string $environment, array $providers): void
    {
        $config = app(EpcisHubPlatformConfig::class);
        $enabled = $config->enabledProviders($environment);

        foreach ($providers as $provider) {
            if (! in_array($provider, $enabled, true)) {
                $enabled[] = $provider;
            }
        }

        $config->setProviders($environment, $enabled);
    }

    private function forgetHubSettings(): void
    {
        foreach (EpcisHubPlatformConfig::ENVIRONMENTS as $environment) {
            PlatformSettings::forget("epcis_hub.{$environment}.providers");
        }
    }

    private function cleanup(): void
    {
        if ($this->routeIds !== []) {
            EpcisHubRoute::query()->whereIn('id', $this->routeIds)->delete();
            $this->routeIds = [];
        }

        Tenant::query()
            ->where('name', 'like', 'Conflict Tenant %')
            ->delete();

        $hasTenantArtifacts = $this->inboundConnectionIds !== [];

        if ($hasTenantArtifacts && ! tenancy()->initialized) {
            $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

            if ($tenant !== null) {
                tenancy()->initialize($tenant);
            }
        }

        if (tenancy()->initialized) {
            if ($this->inboundConnectionIds !== []) {
                InboundConnection::query()->whereIn('id', $this->inboundConnectionIds)->delete();
                $this->inboundConnectionIds = [];
            }

            if ($this->partnerIds !== []) {
                TradingPartner::query()->whereIn('id', $this->partnerIds)->delete();
                $this->partnerIds = [];
            }

            tenancy()->end();
        }
    }
}
