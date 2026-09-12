<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Actions\Integrations\RegisterEpcisHubRoute;
use App\Enums\EpcisReceivedVia;
use App\Enums\InboundTransport;
use App\Enums\PartnerType;
use App\Enums\SerializationProvider;
use App\Enums\TenantProfile;
use App\Models\EpcisHubRoute;
use App\Models\InboundConnection;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Services\Epcis\Outbound\As2SmimeEnvelope;
use App\Support\EpcisHub\EpcisHubPlatformConfig;
use App\Support\Integrations\PlatformAs2Station;
use App\Support\PlatformSettings;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CleansDemo2EpcisArtifacts;
use Tests\TestCase;

class As2HubInboundWebhookTest extends TestCase
{
    use CleansDemo2EpcisArtifacts;

    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const DEMO2_GLN = '0366159000010';

    private const STAGE_HOST = 'stage.tracepharma.io';

    private const STATION_ID = 'TRACEPHARMA-STAGE';

    private const SENDER_ID = 'PARTNER-AS2';

    private const SENDER_GLN = '0301160000009';

    private static bool $demo2TenantReady = false;

    /** @var array{cert: string, key: string}|null */
    private static ?array $stationPems = null;

    /** @var array{cert: string, key: string}|null */
    private static ?array $senderPems = null;

    /** @var array{cert: string, key: string}|null */
    private static ?array $roguePems = null;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'tracepharma.epcis_hub.stage.host' => self::STAGE_HOST,
            'tracepharma.epcis_hub.testing_hosts' => [
                'localhost' => 'stage',
                self::STAGE_HOST => 'stage',
            ],
        ]);

        app(EpcisHubPlatformConfig::class)->setProviders('stage', ['tracepharma']);

        $station = app(PlatformAs2Station::class);
        $station->save('stage', [
            'station_id' => self::STATION_ID,
            'decrypt_cert_pem' => $this->stationPems()['cert'],
            'decrypt_key_pem' => $this->stationPems()['key'],
            'signing_cert_pem' => $this->stationPems()['cert'],
            'signing_key_pem' => $this->stationPems()['key'],
        ]);
        $station->setSenders('stage', [
            [
                'label' => 'Partner',
                'as2_id' => self::SENDER_ID,
                'signing_cert_pem' => $this->senderPems()['cert'],
                'sender_glns' => [self::SENDER_GLN],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        foreach (['station_id', 'signing_cert_pem', 'signing_key_pem', 'decrypt_cert_pem', 'decrypt_key_pem', 'senders'] as $key) {
            PlatformSettings::forget("platform_as2.stage.{$key}");
        }

        PlatformSettings::forget('epcis_hub.stage.providers');

        parent::tearDown();
    }

    #[Test]
    public function signed_and_encrypted_as2_message_is_routed_to_the_owning_tenant(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $original = $this->configureTenantForTracepharmaHub($tenant);

        try {
            $connection = $this->registerTracepharmaHubConnection($tenant);
            $this->resetCacheStoreForTenancy();

            $envelope = app(As2SmimeEnvelope::class)->envelope(
                payload: $this->routedFixtureXml(),
                signingCertPem: $this->senderPems()['cert'],
                signingKeyPem: $this->senderPems()['key'],
                partnerEncryptCertPem: $this->stationPems()['cert'],
            );

            $response = $this->call(
                'POST',
                'https://'.self::STAGE_HOST.'/api/webhooks/as2/hub',
                [],
                [],
                [],
                $this->as2Headers($envelope->contentType),
                $envelope->body,
            );

            $response->assertOk();
            $this->assertStringContainsString('automatic-action/MDN-sent-automatically; processed', $response->getContent());
            $this->assertSame(self::STATION_ID, $response->headers->get('AS2-From'));
            $this->assertSame(self::SENDER_ID, $response->headers->get('AS2-To'));

            $documentId = (int) $response->headers->get('X-Document-Id');
            $this->assertGreaterThan(0, $documentId);
            $this->trackEpcisDocumentId($documentId);

            $tenant->run(function () use ($connection, $documentId): void {
                $this->assertDatabaseHas('epcis_documents', [
                    'id' => $documentId,
                    'inbound_connection_id' => $connection->id,
                    'received_via' => EpcisReceivedVia::As2Hub->value,
                ]);
            });
        } finally {
            $this->restoreTenant($tenant, $original);
            $tenant->run(fn () => $this->cleanupTrackedEpcisArtifacts());
            tenancy()->end();
        }
    }

    #[Test]
    public function as2_from_cannot_impersonate_another_sbdh_sender_gln(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $original = $this->configureTenantForTracepharmaHub($tenant);

        try {
            // Register a second trading partner / connection for the spoofed GLN so
            // hub routing would succeed if AS2-From were not bound to sender GLN.
            $tenant->run(function (): void {
                $partner = TradingPartner::query()->firstOrCreate(
                    ['gln' => '0614141000012'],
                    [
                        'name' => 'Spoofed hub sender',
                        'partner_type' => PartnerType::Wholesaler,
                        'country_code' => 'US',
                        'is_active' => true,
                    ],
                );

                $connection = InboundConnection::query()->create([
                    'name' => 'Spoofed TracePharma Hub',
                    'serialization_provider' => SerializationProvider::TracePharma,
                    'transport' => InboundTransport::Https,
                    'trading_partner_id' => $partner->id,
                    'is_active' => true,
                ]);
                $this->trackInboundConnectionId((int) $connection->id);
                app(RegisterEpcisHubRoute::class)->register($connection);
            });
            $this->registerTracepharmaHubConnection($tenant);
            $this->resetCacheStoreForTenancy();

            $xml = $this->routedFixtureXml();
            $xml = str_replace(self::SENDER_GLN, '0614141000012', $xml);

            $envelope = app(As2SmimeEnvelope::class)->envelope(
                payload: $xml,
                signingCertPem: $this->senderPems()['cert'],
                signingKeyPem: $this->senderPems()['key'],
                partnerEncryptCertPem: $this->stationPems()['cert'],
            );

            $response = $this->call(
                'POST',
                'https://'.self::STAGE_HOST.'/api/webhooks/as2/hub',
                [],
                [],
                [],
                $this->as2Headers($envelope->contentType),
                $envelope->body,
            );

            $response->assertOk();
            $this->assertStringContainsString('failed/failure', $response->getContent());
            $this->assertStringContainsString('not authorized for SBDH sender GLN', $response->getContent());
            $this->assertNull($response->headers->get('X-Document-Id'));
        } finally {
            $this->restoreTenant($tenant, $original);
            $tenant->run(fn () => $this->cleanupTrackedEpcisArtifacts());
            tenancy()->end();
        }
    }

    #[Test]
    public function as2_sender_without_allowed_glns_is_rejected(): void
    {
        app(PlatformAs2Station::class)->setSenders('stage', [
            [
                'label' => 'Partner unbound',
                'as2_id' => self::SENDER_ID,
                'signing_cert_pem' => $this->senderPems()['cert'],
                'sender_glns' => [],
            ],
        ]);

        $tenant = $this->initializeDemo2Tenant();
        $original = $this->configureTenantForTracepharmaHub($tenant);

        try {
            $this->registerTracepharmaHubConnection($tenant);
            $this->resetCacheStoreForTenancy();

            $envelope = app(As2SmimeEnvelope::class)->envelope(
                payload: $this->routedFixtureXml(),
                signingCertPem: $this->senderPems()['cert'],
                signingKeyPem: $this->senderPems()['key'],
                partnerEncryptCertPem: $this->stationPems()['cert'],
            );

            $response = $this->call(
                'POST',
                'https://'.self::STAGE_HOST.'/api/webhooks/as2/hub',
                [],
                [],
                [],
                $this->as2Headers($envelope->contentType),
                $envelope->body,
            );

            $response->assertOk();
            $this->assertStringContainsString('failed/failure', $response->getContent());
            $this->assertStringContainsString('no allowed sender GLNs', $response->getContent());
        } finally {
            $this->restoreTenant($tenant, $original);
            $tenant->run(fn () => $this->cleanupTrackedEpcisArtifacts());
            tenancy()->end();
        }
    }

    #[Test]
    public function unknown_as2_sender_is_forbidden(): void
    {
        $envelope = app(As2SmimeEnvelope::class)->envelope(
            payload: $this->routedFixtureXml(),
            signingCertPem: $this->senderPems()['cert'],
            signingKeyPem: $this->senderPems()['key'],
            partnerEncryptCertPem: $this->stationPems()['cert'],
        );

        $headers = $this->as2Headers($envelope->contentType);
        $headers['HTTP_AS2_FROM'] = 'ROGUE-SENDER';

        $this->call(
            'POST',
            'https://'.self::STAGE_HOST.'/api/webhooks/as2/hub',
            [],
            [],
            [],
            $headers,
            $envelope->body,
        )->assertForbidden();
    }

    #[Test]
    public function as2_to_mismatch_is_forbidden(): void
    {
        $envelope = app(As2SmimeEnvelope::class)->envelope(
            payload: $this->routedFixtureXml(),
            signingCertPem: $this->senderPems()['cert'],
            signingKeyPem: $this->senderPems()['key'],
            partnerEncryptCertPem: $this->stationPems()['cert'],
        );

        $headers = $this->as2Headers($envelope->contentType);
        $headers['HTTP_AS2_TO'] = 'SOMEONE-ELSE';

        $this->call(
            'POST',
            'https://'.self::STAGE_HOST.'/api/webhooks/as2/hub',
            [],
            [],
            [],
            $headers,
            $envelope->body,
        )->assertForbidden();
    }

    #[Test]
    public function signature_signed_by_an_unregistered_key_fails_verification(): void
    {
        // Sender ID is registered, but the message was signed with a different key.
        $envelope = app(As2SmimeEnvelope::class)->envelope(
            payload: $this->routedFixtureXml(),
            signingCertPem: $this->roguePems()['cert'],
            signingKeyPem: $this->roguePems()['key'],
            partnerEncryptCertPem: $this->stationPems()['cert'],
        );

        $response = $this->call(
            'POST',
            'https://'.self::STAGE_HOST.'/api/webhooks/as2/hub',
            [],
            [],
            [],
            $this->as2Headers($envelope->contentType),
            $envelope->body,
        );

        $response->assertOk();
        $this->assertStringContainsString('failed/failure', $response->getContent());
    }

    #[Test]
    public function unrouted_receiver_gln_gets_a_failure_mdn(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $original = $this->configureTenantForTracepharmaHub($tenant);

        try {
            $this->registerTracepharmaHubConnection($tenant);
            $this->resetCacheStoreForTenancy();

            // Fixture keeps its original receiver GLN (0096295000009) — no hub route exists for it.
            $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
            $xml = file_get_contents($fixture);
            $this->assertNotFalse($xml);

            $envelope = app(As2SmimeEnvelope::class)->envelope(
                payload: $xml,
                signingCertPem: $this->senderPems()['cert'],
                signingKeyPem: $this->senderPems()['key'],
                partnerEncryptCertPem: $this->stationPems()['cert'],
            );

            $response = $this->call(
                'POST',
                'https://'.self::STAGE_HOST.'/api/webhooks/as2/hub',
                [],
                [],
                [],
                $this->as2Headers($envelope->contentType),
                $envelope->body,
            );

            $response->assertOk();
            $this->assertStringContainsString('failed/failure', $response->getContent());
        } finally {
            $this->restoreTenant($tenant, $original);
            $tenant->run(fn () => $this->cleanupTrackedEpcisArtifacts());
            tenancy()->end();
        }
    }

    #[Test]
    public function environment_without_a_configured_station_returns_404(): void
    {
        $this->call(
            'POST',
            'https://prod.tracepharma.io/api/webhooks/as2/hub',
            [],
            [],
            [],
            $this->as2Headers('application/xml'),
            '<epcis:EPCISDocument/>',
        )->assertNotFound();
    }

    /**
     * @return array<string, string>
     */
    private function as2Headers(string $contentType): array
    {
        return [
            'HTTP_AS2_FROM' => self::SENDER_ID,
            'HTTP_AS2_TO' => self::STATION_ID,
            'HTTP_MESSAGE_ID' => '<as2-hub-'.str()->uuid().'@test>',
            'HTTP_X_ORIGINAL_FILENAME' => 'as2-hub-inbound.xml',
            'CONTENT_TYPE' => $contentType,
        ];
    }

    private function routedFixtureXml(): string
    {
        $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
        $this->assertFileExists($fixture);
        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);

        $xml = str_replace('0096295000009', self::DEMO2_GLN, $xml);

        return str_replace('11111111-2222-3333-4444-555555555555', (string) str()->uuid(), $xml);
    }

    /**
     * @return array{gln: mixed, inbound_environment: mixed, hub_providers: mixed}
     */
    private function configureTenantForTracepharmaHub(Tenant $tenant): array
    {
        $original = [
            'gln' => $tenant->gln,
            'inbound_environment' => $tenant->inbound_environment,
            'hub_providers' => $tenant->hub_providers,
        ];

        $tenant->forceFill([
            'gln' => self::DEMO2_GLN,
            'inbound_environment' => 'stage',
            'hub_providers' => ['tracepharma'],
        ])->save();

        return $original;
    }

    /**
     * @param  array{gln: mixed, inbound_environment: mixed, hub_providers: mixed}  $original
     */
    private function restoreTenant(Tenant $tenant, array $original): void
    {
        $tenant->forceFill($original)->save();

        EpcisHubRoute::query()
            ->where('tenant_id', $tenant->id)
            ->where('provider', 'tracepharma')
            ->delete();
    }

    private function registerTracepharmaHubConnection(Tenant $tenant): InboundConnection
    {
        $connection = $tenant->run(function (): InboundConnection {
            $partner = TradingPartner::query()->firstOrCreate(
                ['gln' => '0301160000009'],
                [
                    'name' => 'AS2 hub fixture sender',
                    'partner_type' => PartnerType::Wholesaler,
                    'country_code' => 'US',
                    'is_active' => true,
                ],
            );

            return InboundConnection::query()->create([
                'name' => 'TracePharma AS2 Hub Test',
                'serialization_provider' => SerializationProvider::TracePharma,
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

        return $connection;
    }

    private function resetCacheStoreForTenancy(): void
    {
        // Stage/prod use the database cache store, which cannot tag. Stancl
        // wraps Cache::__call with tags() under tenancy — reproduce that here
        // after hub registration (which also touches cache).
        config(['cache.default' => 'database']);
        Cache::clearResolvedInstances();
        $this->app->forgetInstance('cache');
        $this->app->forgetInstance('cache.store');
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

    /**
     * @return array{cert: string, key: string}
     */
    private function stationPems(): array
    {
        return self::$stationPems ??= $this->generatePemPair('tracepharma-as2-station-test');
    }

    /**
     * @return array{cert: string, key: string}
     */
    private function senderPems(): array
    {
        return self::$senderPems ??= $this->generatePemPair('tracepharma-as2-sender-test');
    }

    /**
     * @return array{cert: string, key: string}
     */
    private function roguePems(): array
    {
        return self::$roguePems ??= $this->generatePemPair('tracepharma-as2-rogue-test');
    }

    /**
     * @return array{cert: string, key: string}
     */
    private function generatePemPair(string $commonName): array
    {
        $config = [
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $privateKey = openssl_pkey_new($config);
        if ($privateKey === false) {
            $this->fail('Unable to generate OpenSSL private key for AS2 hub tests.');
        }

        $csr = openssl_csr_new(['commonName' => $commonName], $privateKey, $config);
        if ($csr === false) {
            $this->fail('Unable to generate OpenSSL CSR for AS2 hub tests.');
        }

        $cert = openssl_csr_sign($csr, null, $privateKey, 1, $config);
        if ($cert === false) {
            $this->fail('Unable to sign OpenSSL certificate for AS2 hub tests.');
        }

        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($privateKey, $keyPem);

        return ['cert' => $certPem, 'key' => $keyPem];
    }
}
