<?php

namespace Tests\Feature\Integrations;

use App\Enums\As2MdnAckMode;
use App\Enums\EpcisReceivedVia;
use App\Enums\InboundTransport;
use App\Enums\SerializationProvider;
use App\Enums\TenantProfile;
use App\Http\Controllers\Webhooks\As2InboundWebhookController;
use App\Models\Epcis\EpcisDocument;
use App\Models\InboundConnection;
use App\Models\Tenant;
use App\Services\Epcis\Outbound\As2SmimeEnvelope;
use App\Support\Tenancy\TenantKillSwitches;
use App\Support\TenantSettings;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\CleansDemo2EpcisArtifacts;
use Tests\TestCase;

class As2InboundConnectionIntegrationTest extends TestCase
{
    use CleansDemo2EpcisArtifacts;

    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var array{cert: string, key: string}|null */
    private static ?array $generatedPemPair = null;

    #[Test]
    public function operator_form_hides_as2_and_connection_exposes_as2_url(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->assertNotContains(
                InboundTransport::As2,
                InboundTransport::operatorSelectable(),
            );

            $connection = $this->createAs2Connection();

            $this->assertStringContainsString((string) $tenant->id, (string) $connection->as2Url());
            $this->assertStringContainsString('/api/webhooks/as2/', (string) $connection->as2Url());
            $this->assertNull($connection->webhookUrl());
        } finally {
            $this->cleanupTrackedEpcisArtifacts();
            tenancy()->end();
        }
    }

    #[Test]
    public function unsigned_xml_is_rejected_unless_the_lab_flag_is_on(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $connection = $this->createAs2Connection();
            $xml = $this->uniqueFixtureXml();

            $rejected = app(As2InboundWebhookController::class)->handle(
                $this->as2Request($tenant, $connection, $xml),
                tenantId: $tenant->id,
                connectionId: (int) $connection->id,
            );

            $this->assertSame(200, $rejected->getStatusCode());
            $this->assertStringContainsString('failed/failure', $rejected->getContent());
            $this->assertStringNotContainsString('decrypt certificate', $rejected->getContent());
            $this->assertSame(0, (int) $rejected->headers->get('X-Document-Id'));
            $this->assertSame(0, EpcisDocument::query()->where('inbound_connection_id', $connection->id)->count());

            $lab = $this->createAs2Connection(settings: [
                'allow_unsigned_xml' => true,
            ]);
            $accepted = app(As2InboundWebhookController::class)->handle(
                $this->as2Request($tenant, $lab, $xml),
                tenantId: $tenant->id,
                connectionId: (int) $lab->id,
            );

            $this->assertStringContainsString('processed', $accepted->getContent());
            $documentId = (int) $accepted->headers->get('X-Document-Id');
            $this->assertGreaterThan(0, $documentId);
            $this->trackEpcisDocumentId($documentId);
            $this->assertDatabaseHas('epcis_documents', [
                'id' => $documentId,
                'inbound_connection_id' => $lab->id,
                'received_via' => EpcisReceivedVia::As2Webhook->value,
            ]);
        } finally {
            $this->cleanupTrackedEpcisArtifacts();
            tenancy()->end();
        }
    }

    #[Test]
    public function production_rejects_unsigned_xml_even_when_lab_flag_is_on(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->app->detectEnvironment(fn () => 'production');

            $lab = $this->createAs2Connection(settings: [
                'allow_unsigned_xml' => true,
            ]);
            $xml = $this->uniqueFixtureXml();

            $rejected = app(As2InboundWebhookController::class)->handle(
                $this->as2Request($tenant, $lab, $xml),
                tenantId: $tenant->id,
                connectionId: (int) $lab->id,
            );

            $this->assertSame(200, $rejected->getStatusCode());
            $this->assertStringContainsString('failed/failure', $rejected->getContent());
            $this->assertSame(0, (int) $rejected->headers->get('X-Document-Id'));
            $this->assertSame(0, EpcisDocument::query()->where('inbound_connection_id', $lab->id)->count());
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
            $this->cleanupTrackedEpcisArtifacts();
            tenancy()->end();
        }
    }

    #[Test]
    public function encrypted_as2_unwraps_with_inbound_decrypt_certs(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $pems = $this->generatedSelfSignedPemPair();
            $connection = $this->createAs2Connection(
                credentials: [
                    'decrypt_cert_pem' => $pems['cert'],
                    'decrypt_key_pem' => $pems['key'],
                ],
                settings: [
                    'allow_unsigned_xml' => true,
                ],
            );
            $xml = $this->uniqueFixtureXml();
            $envelope = app(As2SmimeEnvelope::class)->envelope(
                payload: $xml,
                signingCertPem: null,
                signingKeyPem: null,
                partnerEncryptCertPem: $pems['cert'],
            );

            $request = $this->as2Request($tenant, $connection, $envelope->body, $envelope->contentType);
            $response = app(As2InboundWebhookController::class)->handle(
                $request,
                tenantId: $tenant->id,
                connectionId: (int) $connection->id,
            );

            $this->assertSame(200, $response->getStatusCode());
            $this->assertStringContainsString('processed', $response->getContent());
            $documentId = (int) $response->headers->get('X-Document-Id');
            $this->trackEpcisDocumentId($documentId);
            $this->assertSame(
                EpcisReceivedVia::As2Webhook,
                EpcisDocument::query()->findOrFail($documentId)->received_via,
            );
        } finally {
            $this->cleanupTrackedEpcisArtifacts();
            tenancy()->end();
        }
    }

    #[Test]
    public function as2_from_to_mismatch_is_forbidden(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $connection = $this->createAs2Connection();
            $request = $this->as2Request($tenant, $connection, $this->uniqueFixtureXml());
            $request->headers->set('AS2-From', 'WRONG-FROM');

            try {
                app(As2InboundWebhookController::class)->handle(
                    $request,
                    tenantId: $tenant->id,
                    connectionId: (int) $connection->id,
                );
                $this->fail('Expected 403 for AS2 identity mismatch.');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        } finally {
            $this->cleanupTrackedEpcisArtifacts();
            tenancy()->end();
        }
    }

    #[Test]
    public function https_connection_is_not_an_as2_endpoint(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $connection = InboundConnection::query()->create([
                'name' => 'HTTPS not AS2 '.substr((string) str()->uuid(), 0, 8),
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => InboundTransport::Https,
                'is_active' => true,
            ]);
            $this->trackInboundConnectionId((int) $connection->id);

            $this->expectException(ModelNotFoundException::class);

            app(As2InboundWebhookController::class)->handle(
                Request::create(
                    uri: '/api/webhooks/as2/'.$tenant->id.'/'.$connection->id,
                    method: 'POST',
                    content: $this->uniqueFixtureXml(),
                    server: [
                        'HTTP_AS2_FROM' => 'PARTNER-AS2',
                        'HTTP_AS2_TO' => 'TRACEPHARMA-AS2',
                        'CONTENT_TYPE' => 'application/xml',
                    ],
                ),
                tenantId: $tenant->id,
                connectionId: (int) $connection->id,
            );
        } finally {
            $this->cleanupTrackedEpcisArtifacts();
            tenancy()->end();
        }
    }

    #[Test]
    public function inbound_kill_switch_blocks_as2_receive(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $connection = $this->createAs2Connection();
            TenantSettings::forTenant($tenant)->setKillSwitch(TenantKillSwitches::INBOUND_EPCIS, true);
            $tenant->save();

            $this->call(
                'POST',
                '/api/webhooks/as2/'.$tenant->id.'/'.$connection->id,
                [],
                [],
                [],
                [
                    'HTTP_AS2_FROM' => 'PARTNER-AS2',
                    'HTTP_AS2_TO' => 'TRACEPHARMA-AS2',
                    'HTTP_MESSAGE_ID' => '<kill-as2@test>',
                    'CONTENT_TYPE' => 'application/xml',
                    'HTTP_ACCEPT' => 'application/json',
                ],
                $this->uniqueFixtureXml(),
            )->assertForbidden()
                ->assertJson([
                    'message' => TenantKillSwitches::blockedMessage(TenantKillSwitches::INBOUND_EPCIS),
                ]);
        } finally {
            TenantSettings::forTenant($tenant)->setKillSwitch(TenantKillSwitches::INBOUND_EPCIS, false);
            $tenant->save();
            $this->cleanupTrackedEpcisArtifacts();
            tenancy()->end();
        }
    }

    /**
     * @param  array<string, string>  $credentials
     * @param  array<string, mixed>  $settings
     */
    private function createAs2Connection(array $credentials = [], array $settings = []): InboundConnection
    {
        $connection = InboundConnection::query()->create([
            'name' => 'AS2 inbound '.substr((string) str()->uuid(), 0, 8),
            'serialization_provider' => SerializationProvider::CustomHttps,
            'transport' => InboundTransport::As2,
            'is_active' => true,
            'settings' => array_merge([
                'as2_from' => 'PARTNER-AS2',
                'as2_to' => 'TRACEPHARMA-AS2',
                'as2_mdn_ack_mode' => As2MdnAckMode::Sync->value,
            ], $settings),
            'credentials' => $credentials,
        ]);
        $this->trackInboundConnectionId((int) $connection->id);

        return $connection;
    }

    private function as2Request(
        Tenant $tenant,
        InboundConnection $connection,
        string $body,
        string $contentType = 'application/xml',
    ): Request {
        return Request::create(
            uri: '/api/webhooks/as2/'.$tenant->id.'/'.$connection->id,
            method: 'POST',
            content: $body,
            server: [
                'HTTP_AS2_FROM' => 'PARTNER-AS2',
                'HTTP_AS2_TO' => 'TRACEPHARMA-AS2',
                'HTTP_MESSAGE_ID' => '<as2-inbound-'.str()->uuid().'@test>',
                'HTTP_X_ORIGINAL_FILENAME' => 'as2-inbound.xml',
                'CONTENT_TYPE' => $contentType,
            ],
        );
    }

    private function uniqueFixtureXml(): string
    {
        $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
        $this->assertFileExists($fixture);
        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);

        return str_replace('11111111-2222-3333-4444-555555555555', (string) str()->uuid(), $xml);
    }

    /**
     * @return array{cert: string, key: string}
     */
    private function generatedSelfSignedPemPair(): array
    {
        if (self::$generatedPemPair !== null) {
            return self::$generatedPemPair;
        }

        $config = [
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $privateKey = openssl_pkey_new($config);
        if ($privateKey === false) {
            $this->fail('Unable to generate OpenSSL private key for AS2 inbound tests.');
        }

        $csr = openssl_csr_new(['commonName' => 'tracepharma-as2-inbound-test'], $privateKey, $config);
        if ($csr === false) {
            $this->fail('Unable to generate OpenSSL CSR for AS2 inbound tests.');
        }

        $cert = openssl_csr_sign($csr, null, $privateKey, 1, $config);
        if ($cert === false) {
            $this->fail('Unable to sign OpenSSL certificate for AS2 inbound tests.');
        }

        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($privateKey, $keyPem);

        self::$generatedPemPair = [
            'cert' => $certPem,
            'key' => $keyPem,
        ];

        return self::$generatedPemPair;
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
}
