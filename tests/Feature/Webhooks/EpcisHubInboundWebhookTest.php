<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Actions\Integrations\RegisterEpcisHubRoute;
use App\Enums\InboundTransport;
use App\Enums\PartnerType;
use App\Enums\SerializationProvider;
use App\Enums\TenantProfile;
use App\Models\EpcisHubRoute;
use App\Models\InboundConnection;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Support\EpcisHub\EpcisHubPlatformConfig;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CleansDemo2EpcisArtifacts;
use Tests\TestCase;

class EpcisHubInboundWebhookTest extends TestCase
{
    use CleansDemo2EpcisArtifacts;

    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const DEMO2_GLN = '0366159000010';

    private const STAGE_HOST = 'stage.tracepharma.io';

    private static bool $demo2TenantReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'tracepharma.epcis_hub.hub_token' => 'hub-secret-token',
            'tracepharma.epcis_hub.stage.host' => self::STAGE_HOST,
            'tracepharma.epcis_hub.stage.hub_token' => 'hub-secret-token',
            'tracepharma.epcis_hub.testing_hosts' => [
                'localhost' => 'stage',
                self::STAGE_HOST => 'stage',
            ],
        ]);

        app(EpcisHubPlatformConfig::class)->setProviders('stage', ['systech', 'unitrace']);
    }

    #[Test]
    public function hub_acknowledges_rich_connectivity_probe(): void
    {
        $response = $this->call(
            'POST',
            'https://'.self::STAGE_HOST.'/api/webhooks/epcis/hub/unitrace',
            [],
            [],
            [],
            [
                'HTTP_X_EPCIS_HUB_TOKEN' => 'hub-secret-token',
                'CONTENT_TYPE' => 'application/xml',
                'HTTP_ACCEPT' => 'application/json',
            ],
            '<root>Connectivity test</root>',
        );

        $response->assertAccepted()
            ->assertJson([
                'message' => 'Connectivity test acknowledged.',
                'connectivity_test' => true,
            ]);
    }

    #[Test]
    public function hub_rejects_invalid_token(): void
    {
        $response = $this->call(
            'POST',
            'https://'.self::STAGE_HOST.'/api/webhooks/epcis/hub/unitrace',
            [],
            [],
            [],
            [
                'HTTP_X_EPCIS_HUB_TOKEN' => 'wrong',
                'CONTENT_TYPE' => 'application/xml',
            ],
        );

        $response->assertUnauthorized();
    }

    #[Test]
    public function hub_routes_epcis_by_receiver_gln_to_demo2(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $originalGln = $tenant->gln;
        $originalInboundEnvironment = $tenant->inbound_environment;
        $originalHubProviders = $tenant->hub_providers;

        try {
            $tenant->forceFill([
                'gln' => self::DEMO2_GLN,
                'inbound_environment' => 'stage',
                'hub_providers' => ['systech', 'unitrace'],
            ])->save();

            $connection = $tenant->run(function (): InboundConnection {
                $partner = TradingPartner::query()->firstOrCreate(
                    ['gln' => '0301160000009'],
                    [
                        'name' => 'Hub webhook fixture sender',
                        'partner_type' => PartnerType::Wholesaler,
                        'country_code' => 'US',
                        'is_active' => true,
                    ],
                );

                return InboundConnection::query()->create([
                    'name' => 'Systech Hub Test',
                    'serialization_provider' => SerializationProvider::Systech,
                    'transport' => InboundTransport::Https,
                    'trading_partner_id' => $partner->id,
                    'is_active' => true,
                ]);
            });
            $this->trackInboundConnectionId((int) $connection->id);

            $tenant->run(function () use ($connection): void {
                app(RegisterEpcisHubRoute::class)->register($connection);
            });

            tenancy()->end();

            // Stage/prod use the database cache store, which cannot tag. Stancl
            // wraps Cache::__call with tags() under tenancy — reproduce that here
            // after hub registration (which also touches cache).
            config(['cache.default' => 'database']);
            \Illuminate\Support\Facades\Cache::clearResolvedInstances();
            $this->app->forgetInstance('cache');
            $this->app->forgetInstance('cache.store');

            $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
            $this->assertFileExists($fixture);
            $xml = file_get_contents($fixture);
            $this->assertNotFalse($xml);
            $xml = str_replace('0096295000009', self::DEMO2_GLN, $xml);
            $xml = str_replace('11111111-2222-3333-4444-555555555555', (string) str()->uuid(), $xml);

            $response = $this->call(
                'POST',
                'https://'.self::STAGE_HOST.'/api/webhooks/epcis/hub/systech',
                [],
                [],
                [],
                [
                    'HTTP_X_EPCIS_HUB_TOKEN' => 'hub-secret-token',
                    'CONTENT_TYPE' => 'application/xml',
                    'HTTP_ACCEPT' => 'application/json',
                ],
                $xml,
            );

            $response->assertAccepted()
                ->assertJsonPath('message', 'EPCIS document accepted for processing.')
                ->assertJsonStructure(['document_id', 'status']);

            $this->trackEpcisDocumentId((int) $response->json('document_id'));

            $tenant->run(function () use ($connection, $response): void {
                $this->assertDatabaseHas('epcis_documents', [
                    'id' => $response->json('document_id'),
                    'inbound_connection_id' => $connection->id,
                ]);
            });
        } finally {
            $tenant->forceFill([
                'gln' => $originalGln,
                'inbound_environment' => $originalInboundEnvironment,
                'hub_providers' => $originalHubProviders,
            ])->save();

            EpcisHubRoute::query()
                ->where('tenant_id', $tenant->id)
                ->where('provider', 'systech')
                ->delete();

            $tenant->run(fn () => $this->cleanupTrackedEpcisArtifacts());

            tenancy()->end();
        }
    }

    #[Test]
    public function unknown_hub_provider_returns_404(): void
    {
        $response = $this->call(
            'POST',
            'https://'.self::STAGE_HOST.'/api/webhooks/epcis/hub/unknown-provider',
            [],
            [],
            [],
            [
                'HTTP_X_EPCIS_HUB_TOKEN' => 'hub-secret-token',
                'CONTENT_TYPE' => 'application/xml',
            ],
        );

        $response->assertNotFound();
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
