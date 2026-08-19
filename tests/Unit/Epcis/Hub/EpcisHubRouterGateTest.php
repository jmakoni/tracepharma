<?php

declare(strict_types=1);

namespace Tests\Unit\Epcis\Hub;

use App\Actions\Integrations\RegisterEpcisHubRoute;
use App\Enums\InboundTransport;
use App\Enums\SerializationProvider;
use App\Enums\TenantProfile;
use App\Models\EpcisHubRoute;
use App\Models\InboundConnection;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Services\Epcis\Hub\EpcisHubRouter;
use App\Support\EpcisHub\EpcisHubPlatformConfig;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class EpcisHubRouterGateTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const DEMO2_GLN = '0366159000010';

    private const ORPHAN_GLN = '0366159000099';

    /** SBDH sender in minimal_object_shipping.xml */
    private const FIXTURE_SENDER_GLN = '0301160000009';

    private const UNKNOWN_SENDER_GLN = '0614141999996';

    private static bool $demo2TenantReady = false;

    /** @var list<string> */
    private array $orphanTenantIds = [];

    /** @var list<int> */
    private array $orphanRouteIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--force' => true,
            '--path' => 'database/migrations/2026_08_16_032000_scope_epcis_hub_routes_unique_to_tenant.php',
        ])->assertSuccessful();

        app(EpcisHubPlatformConfig::class)->setProviders('stage', ['systech', 'unitrace']);
        app(EpcisHubPlatformConfig::class)->setProviders('prod', ['systech', 'unitrace']);
    }

    protected function tearDown(): void
    {
        if ($this->orphanRouteIds !== []) {
            EpcisHubRoute::query()->whereIn('id', $this->orphanRouteIds)->delete();
        }

        if ($this->orphanTenantIds !== []) {
            EpcisHubRoute::query()->whereIn('tenant_id', $this->orphanTenantIds)->delete();
        }

        foreach ($this->orphanTenantIds as $tenantId) {
            Tenant::withoutEvents(fn () => Tenant::query()->whereKey($tenantId)->delete());
        }

        parent::tearDown();
    }

    #[Test]
    public function tenant_without_hub_providers_fails_routing(): void
    {
        $this->createOrphanTenant([
            'gln' => self::ORPHAN_GLN,
            'inbound_environment' => 'stage',
            'hub_providers' => null,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not enabled for this tenant');

        app(EpcisHubRouter::class)->resolve('systech', $this->xmlForReceiver(self::ORPHAN_GLN), 'stage');
    }

    #[Test]
    public function wrong_inbound_environment_fails_routing(): void
    {
        $this->createOrphanTenant([
            'gln' => self::ORPHAN_GLN,
            'inbound_environment' => 'prod',
            'hub_providers' => ['systech'],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No tenant is registered for receiver GLN');

        app(EpcisHubRouter::class)->resolve('systech', $this->xmlForReceiver(self::ORPHAN_GLN), 'stage');
    }

    #[Test]
    public function paired_tenants_sharing_a_gln_resolve_by_inbound_environment(): void
    {
        $stage = $this->createOrphanTenant([
            'gln' => self::ORPHAN_GLN,
            'inbound_environment' => 'stage',
            'hub_providers' => null,
        ]);
        $prod = $this->createOrphanTenant([
            'gln' => self::ORPHAN_GLN,
            'inbound_environment' => 'prod',
            'hub_providers' => ['systech'],
        ]);

        try {
            app(EpcisHubRouter::class)->resolve('systech', $this->xmlForReceiver(self::ORPHAN_GLN), 'stage');
            $this->fail('Stage sibling without hub providers should fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('not enabled for this tenant', $exception->getMessage());
            $this->assertStringNotContainsString('multiple tenant', $exception->getMessage());
        }

        $stage->forceFill(['hub_providers' => ['systech']])->save();
        $prod->forceFill(['hub_providers' => null])->save();

        try {
            app(EpcisHubRouter::class)->resolve('systech', $this->xmlForReceiver(self::ORPHAN_GLN), 'prod');
            $this->fail('Prod sibling without hub providers should fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('not enabled for this tenant', $exception->getMessage());
            $this->assertStringNotContainsString('multiple tenant', $exception->getMessage());
        }
    }

    #[Test]
    public function hub_route_for_the_other_pair_sibling_falls_through_to_gln_and_env(): void
    {
        $this->createOrphanTenant([
            'gln' => self::ORPHAN_GLN,
            'inbound_environment' => 'stage',
            'hub_providers' => null,
        ]);
        $prod = $this->createOrphanTenant([
            'gln' => self::ORPHAN_GLN,
            'inbound_environment' => 'prod',
            'hub_providers' => ['systech'],
        ]);

        $route = EpcisHubRoute::query()->create([
            'tenant_id' => $prod->id,
            'provider' => 'systech',
            'gln' => self::ORPHAN_GLN,
            'default_inbound_connection_id' => 1,
            'is_active' => true,
        ]);
        $this->orphanRouteIds[] = (int) $route->id;

        try {
            app(EpcisHubRouter::class)->resolve('systech', $this->xmlForReceiver(self::ORPHAN_GLN), 'stage');
            $this->fail('Stage sibling without hub providers should fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('not enabled for this tenant', $exception->getMessage());
            $this->assertStringNotContainsString('does not match hub environment', $exception->getMessage());
            $this->assertStringNotContainsString('multiple tenant', $exception->getMessage());
        }
    }

    #[Test]
    public function pair_siblings_can_each_register_a_hub_route_for_the_same_gln(): void
    {
        $stage = $this->createOrphanTenant([
            'gln' => self::ORPHAN_GLN,
            'inbound_environment' => 'stage',
            'hub_providers' => ['systech'],
        ]);
        $prod = $this->createOrphanTenant([
            'gln' => self::ORPHAN_GLN,
            'inbound_environment' => 'prod',
            'hub_providers' => ['systech'],
        ]);

        foreach ([$stage, $prod] as $tenant) {
            $route = EpcisHubRoute::query()->create([
                'tenant_id' => $tenant->id,
                'provider' => 'systech',
                'gln' => self::ORPHAN_GLN,
                'default_inbound_connection_id' => 1,
                'is_active' => true,
            ]);
            $this->orphanRouteIds[] = (int) $route->id;
        }

        $this->assertSame(2, EpcisHubRoute::query()
            ->where('provider', 'systech')
            ->where('gln', self::ORPHAN_GLN)
            ->whereIn('tenant_id', [$stage->id, $prod->id])
            ->count());
    }

    #[Test]
    public function two_tenants_in_the_same_inbound_environment_still_conflict(): void
    {
        $this->createOrphanTenant([
            'gln' => self::ORPHAN_GLN,
            'inbound_environment' => 'stage',
            'hub_providers' => ['systech'],
        ]);
        $this->createOrphanTenant([
            'gln' => self::ORPHAN_GLN,
            'inbound_environment' => 'stage',
            'hub_providers' => ['systech'],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('matches multiple tenant records');

        app(EpcisHubRouter::class)->resolve('systech', $this->xmlForReceiver(self::ORPHAN_GLN), 'stage');
    }

    #[Test]
    public function matching_env_and_providers_routes_successfully(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $original = [
            'gln' => $tenant->gln,
            'inbound_environment' => $tenant->inbound_environment,
            'hub_providers' => $tenant->hub_providers,
        ];

        $tenant->forceFill([
            'gln' => self::DEMO2_GLN,
            'inbound_environment' => 'stage',
            'hub_providers' => ['systech'],
        ])->save();

        $connection = $tenant->run(function (): InboundConnection {
            $partner = TradingPartner::query()->firstOrCreate(
                ['gln' => self::FIXTURE_SENDER_GLN],
                [
                    'name' => 'Hub router fixture sender',
                    'partner_type' => \App\Enums\PartnerType::Wholesaler,
                    'country_code' => 'US',
                    'is_active' => true,
                ],
            );

            return InboundConnection::query()->create([
                'name' => 'Router gate success',
                'serialization_provider' => SerializationProvider::Systech,
                'transport' => InboundTransport::Https,
                'trading_partner_id' => $partner->id,
                'is_active' => true,
            ]);
        });

        try {
            $tenant->run(fn () => app(RegisterEpcisHubRoute::class)->register($connection));
            tenancy()->end();

            $resolution = app(EpcisHubRouter::class)->resolve(
                'systech',
                $this->xmlForReceiver(self::DEMO2_GLN),
                'stage',
            );

            $this->assertFalse($resolution->isProbe());
            $this->assertSame($tenant->id, $resolution->tenant?->id);
            $this->assertSame($connection->id, $resolution->connection?->id);
        } finally {
            EpcisHubRoute::query()
                ->where('tenant_id', $tenant->id)
                ->where('provider', 'systech')
                ->delete();

            $tenant->run(fn () => $connection->delete());
            $tenant->forceFill($original)->save();
            tenancy()->end();
        }
    }

    #[Test]
    public function sbdh_sgln_identifiers_route_by_normalized_gln(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $original = [
            'gln' => $tenant->gln,
            'inbound_environment' => $tenant->inbound_environment,
            'hub_providers' => $tenant->hub_providers,
        ];

        $tenant->forceFill([
            'gln' => self::DEMO2_GLN,
            'inbound_environment' => 'stage',
            'hub_providers' => ['systech'],
        ])->save();

        $connection = $tenant->run(function (): InboundConnection {
            $partner = TradingPartner::query()->firstOrCreate(
                ['gln' => self::FIXTURE_SENDER_GLN],
                [
                    'name' => 'Hub router SGLN sender',
                    'partner_type' => \App\Enums\PartnerType::Wholesaler,
                    'country_code' => 'US',
                    'is_active' => true,
                ],
            );

            return InboundConnection::query()->create([
                'name' => 'Router SGLN gate',
                'serialization_provider' => SerializationProvider::Systech,
                'transport' => InboundTransport::Https,
                'trading_partner_id' => $partner->id,
                'is_active' => true,
            ]);
        });

        try {
            $tenant->run(fn () => app(RegisterEpcisHubRoute::class)->register($connection));
            tenancy()->end();

            $senderSgln = 'urn:epc:id:sgln:030116.000000.0';

            $xml = $this->xmlForReceiver(self::DEMO2_GLN);
            $xml = str_replace(self::FIXTURE_SENDER_GLN, $senderSgln, $xml);

            $resolution = app(EpcisHubRouter::class)->resolve('systech', $xml, 'stage');

            $this->assertSame($tenant->id, $resolution->tenant?->id);
            $this->assertSame($connection->id, $resolution->connection?->id);
            $this->assertSame(self::DEMO2_GLN, $resolution->receiverGln);
            $this->assertSame(self::FIXTURE_SENDER_GLN, $resolution->senderGln);
        } finally {
            EpcisHubRoute::query()
                ->where('tenant_id', $tenant->id)
                ->where('provider', 'systech')
                ->delete();

            $tenant->run(fn () => $connection->delete());
            $tenant->forceFill($original)->save();
            tenancy()->end();
        }
    }

    #[Test]
    public function preferred_connection_does_not_bypass_unknown_sender_fail_closed(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $original = [
            'gln' => $tenant->gln,
            'inbound_environment' => $tenant->inbound_environment,
            'hub_providers' => $tenant->hub_providers,
        ];

        $tenant->forceFill([
            'gln' => self::DEMO2_GLN,
            'inbound_environment' => 'stage',
            'hub_providers' => ['systech'],
        ])->save();

        $connection = $tenant->run(fn (): InboundConnection => InboundConnection::query()->create([
            'name' => 'Preferred without matching sender',
            'serialization_provider' => SerializationProvider::Systech,
            'transport' => InboundTransport::Https,
            'is_active' => true,
        ]));

        try {
            $tenant->run(fn () => app(RegisterEpcisHubRoute::class)->register($connection));
            tenancy()->end();

            $xml = $this->xmlForReceiver(self::DEMO2_GLN);
            $xml = str_replace(self::FIXTURE_SENDER_GLN, self::UNKNOWN_SENDER_GLN, $xml);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('is not registered to a trading partner for hub routing');

            app(EpcisHubRouter::class)->resolve('systech', $xml, 'stage');
        } finally {
            EpcisHubRoute::query()
                ->where('tenant_id', $tenant->id)
                ->where('provider', 'systech')
                ->delete();

            $tenant->run(fn () => $connection->delete());
            $tenant->forceFill($original)->save();
            tenancy()->end();
        }
    }

    #[Test]
    public function preferred_connection_tie_breaks_among_matched_senders(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $original = [
            'gln' => $tenant->gln,
            'inbound_environment' => $tenant->inbound_environment,
            'hub_providers' => $tenant->hub_providers,
        ];

        $tenant->forceFill([
            'gln' => self::DEMO2_GLN,
            'inbound_environment' => 'stage',
            'hub_providers' => ['systech'],
        ])->save();

        [$preferred, $other] = $tenant->run(function (): array {
            $partner = TradingPartner::query()->firstOrCreate(
                ['gln' => self::FIXTURE_SENDER_GLN],
                [
                    'name' => 'Hub router fixture sender',
                    'partner_type' => \App\Enums\PartnerType::Wholesaler,
                    'country_code' => 'US',
                    'is_active' => true,
                ],
            );

            $preferred = InboundConnection::query()->create([
                'name' => 'Z Preferred matched',
                'serialization_provider' => SerializationProvider::Systech,
                'transport' => InboundTransport::Https,
                'trading_partner_id' => $partner->id,
                'is_active' => true,
            ]);

            $other = InboundConnection::query()->create([
                'name' => 'A Other matched',
                'serialization_provider' => SerializationProvider::Systech,
                'transport' => InboundTransport::Https,
                'trading_partner_id' => $partner->id,
                'is_active' => true,
            ]);

            return [$preferred, $other];
        });

        try {
            $tenant->run(fn () => app(RegisterEpcisHubRoute::class)->register($preferred));
            tenancy()->end();

            $resolution = app(EpcisHubRouter::class)->resolve(
                'systech',
                $this->xmlForReceiver(self::DEMO2_GLN),
                'stage',
            );

            $this->assertSame($preferred->id, $resolution->connection?->id);
        } finally {
            EpcisHubRoute::query()
                ->where('tenant_id', $tenant->id)
                ->where('provider', 'systech')
                ->delete();

            $tenant->run(function () use ($preferred, $other): void {
                $preferred->delete();
                $other->delete();
            });
            $tenant->forceFill($original)->save();
            tenancy()->end();
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createOrphanTenant(array $attributes): Tenant
    {
        $id = (string) Str::uuid();
        $this->orphanTenantIds[] = $id;

        return Tenant::withoutEvents(fn () => Tenant::query()->create(array_merge([
            'id' => $id,
            'name' => 'Hub router orphan',
            'profile' => TenantProfile::Pharmacy,
            'status' => 'active',
            'tenancy_db_name' => 'tenant_hub_orphan_'.substr(str_replace('-', '', $id), 0, 16),
        ], $attributes)));
    }

    private function xmlForReceiver(string $receiverGln): string
    {
        $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);

        return str_replace('0096295000009', $receiverGln, $xml);
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

        tenancy()->initialize($tenant);

        return $tenant;
    }
}
