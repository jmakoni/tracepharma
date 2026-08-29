<?php

namespace Tests\Feature\Integrations;

use App\Enums\As2MdnAckMode;
use App\Enums\OutboundTransport;
use App\Enums\PartnerType;
use App\Enums\SerializationProvider;
use App\Enums\TenantProfile;
use App\Filament\App\Concerns\TransformsConnectionCredentials;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\TransmissionMdn;
use App\Models\OutboundConnection;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Services\Epcis\Contracts\OutboundEpcisTransmitter;
use App\Services\Epcis\Outbound\As2OutboundSender;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class As2OutboundConnectionIntegrationTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?int $connectionId = null;

    private ?int $documentId = null;

    /** @var list<int> */
    private array $transmissionMdnIds = [];

    /** @var list<int> */
    private array $partnerIds = [];

    private const SAMPLE_SIGNING_CERT_PEM = "-----BEGIN CERTIFICATE-----\nMIIBtest-signing-cert\n-----END CERTIFICATE-----";

    private const SAMPLE_SIGNING_KEY_PEM = "-----BEGIN PRIVATE KEY-----\nMIIBtest-signing-key\n-----END PRIVATE KEY-----";

    private const SAMPLE_PARTNER_ENCRYPT_CERT_PEM = "-----BEGIN CERTIFICATE-----\nMIIBtest-partner-encrypt-cert\n-----END CERTIFICATE-----";

    private const AS2_MDN_WEBHOOK_SECRET = 'test-as2-mdn-webhook-secret';

    /** @var array{cert: string, key: string}|null */
    private static ?array $generatedPemPair = null;

    /**
     * @return array{cert: string, key: string}
     */
    private static function generatedSelfSignedPemPair(): array
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
            self::fail('Unable to generate OpenSSL private key for AS2 S/MIME tests.');
        }

        $csr = openssl_csr_new(['commonName' => 'tracepharma-as2-test'], $privateKey, $config);

        if ($csr === false) {
            self::fail('Unable to generate OpenSSL CSR for AS2 S/MIME tests.');
        }

        $certificate = openssl_csr_sign($csr, null, $privateKey, 365, $config);

        if ($certificate === false) {
            self::fail('Unable to sign OpenSSL certificate for AS2 S/MIME tests.');
        }

        openssl_x509_export($certificate, $certPem);
        openssl_pkey_export($privateKey, $keyPem);

        self::$generatedPemPair = [
            'cert' => $certPem,
            'key' => $keyPem,
        ];

        return self::$generatedPemPair;
    }

    #[Test]
    public function as2_connection_form_transform_persists_encrypted_certificate_credentials(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $connection = OutboundConnection::query()->create([
                'name' => 'Partner AS2 Certs',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::As2,
                'is_active' => true,
                'settings' => [
                    'as2_url' => 'https://partner-as2.example/as2',
                    'as2_from' => 'tracepharma-as2-id',
                    'as2_to' => 'partner-as2-id',
                    'as2_mdn_ack_mode' => As2MdnAckMode::Sync->value,
                ],
            ]);
            $this->connectionId = (int) $connection->getKey();

            $transformer = new class
            {
                use TransformsConnectionCredentials;

                /**
                 * @param  array<string, mixed>  $data
                 * @return array<string, mixed>
                 */
                public function save(array $data, ?array $existing = null): array
                {
                    return $this->transformOutboundCredentialPairs($data, $existing);
                }
            };

            $payload = $transformer->save([
                'as2_signing_cert_pem' => self::SAMPLE_SIGNING_CERT_PEM,
                'as2_signing_key_pem' => self::SAMPLE_SIGNING_KEY_PEM,
                'as2_partner_encrypt_cert_pem' => self::SAMPLE_PARTNER_ENCRYPT_CERT_PEM,
            ]);

            $connection->forceFill([
                'credentials' => $payload['credentials'] ?? [],
            ])->save();

            $connection->refresh();

            $this->assertSame(self::SAMPLE_SIGNING_CERT_PEM, $connection->credentials['signing_cert_pem'] ?? null);
            $this->assertSame(self::SAMPLE_SIGNING_KEY_PEM, $connection->credentials['signing_key_pem'] ?? null);
            $this->assertSame(self::SAMPLE_PARTNER_ENCRYPT_CERT_PEM, $connection->credentials['partner_encrypt_cert_pem'] ?? null);
            $this->assertTrue($connection->as2CertificatesConfigured());
            $this->assertTrue($connection->as2SmimeActive());
            $this->assertNotSame(self::SAMPLE_SIGNING_CERT_PEM, $connection->getRawOriginal('credentials'));
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function as2_transmit_with_certificates_applies_smime_envelope(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $pemPair = self::generatedSelfSignedPemPair();

            $mdnBody = "Reporting-UA: partner-as2.example\r\nOriginal-Message-ID: <test@tracepharma>\r\nDisposition: automatic-action/MDN-sent-automatically; processed";
            $xml = $this->schemaValidOutboundXml();

            Http::fake([
                'https://partner-as2.example/as2' => Http::response($mdnBody, 200, [
                    'Content-Type' => 'multipart/report; report-type=disposition-notification',
                ]),
            ]);

            $connection = OutboundConnection::query()->create([
                'name' => 'Partner AS2 With Certs',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::As2,
                'is_active' => true,
                'settings' => [
                    'as2_url' => 'https://partner-as2.example/as2',
                    'as2_from' => 'tracepharma-as2-id',
                    'as2_to' => 'partner-as2-id',
                    'as2_mdn_ack_mode' => As2MdnAckMode::Sync->value,
                ],
                'credentials' => [
                    'signing_cert_pem' => $pemPair['cert'],
                    'signing_key_pem' => $pemPair['key'],
                    'partner_encrypt_cert_pem' => $pemPair['cert'],
                ],
            ]);
            $this->connectionId = (int) $connection->getKey();

            $result = app(As2OutboundSender::class)->send($connection, $xml, 'test-as2-transmit.xml');

            Http::assertSent(function ($request) use ($xml): bool {
                $body = $request->body();
                $contentType = $request->header('Content-Type')[0] ?? '';

                return $request->url() === 'https://partner-as2.example/as2'
                    && $body !== $xml
                    && str_contains(strtolower($contentType), 'pkcs7-mime')
                    && str_contains($body, 'MII');
            });

            $this->assertTrue($result->smimeApplied);
            $this->assertTrue($result->certificatesConfigured);
            $this->assertSame('received', $result->mdnStatus);
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function sync_mdn_requires_mdn_content_type_not_body_substring_alone(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $mdnBody = "Reporting-UA: partner-as2.example\r\nOriginal-Message-ID: <test@tracepharma>\r\nDisposition: automatic-action/MDN-sent-automatically; processed";

            Http::fake([
                'https://partner-as2.example/as2' => Http::response($mdnBody, 200, [
                    'Content-Type' => 'application/xml',
                ]),
            ]);

            $connection = OutboundConnection::query()->create([
                'name' => 'Partner AS2 Wrong MDN Content-Type',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::As2,
                'is_active' => true,
                'settings' => [
                    'as2_url' => 'https://partner-as2.example/as2',
                    'as2_from' => 'tracepharma-as2-id',
                    'as2_to' => 'partner-as2-id',
                    'as2_mdn_ack_mode' => As2MdnAckMode::Sync->value,
                ],
            ]);
            $this->connectionId = (int) $connection->getKey();

            $result = app(As2OutboundSender::class)->send(
                $connection,
                '<?xml version="1.0"?><epcis:EPCISDocument xmlns:epcis="urn:epcglobal:epcis:xsd:1"></epcis:EPCISDocument>',
                'test-as2-wrong-mdn-content-type.xml',
            );

            $this->assertNull($result->mdnBody);
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function sync_mdn_accepts_multipart_report_content_type(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $mdnBody = "Reporting-UA: partner-as2.example\r\nOriginal-Message-ID: <test@tracepharma>\r\nDisposition: automatic-action/MDN-sent-automatically; processed";

            Http::fake([
                'https://partner-as2.example/as2' => Http::response($mdnBody, 200, [
                    'Content-Type' => 'multipart/report; report-type=disposition-notification',
                ]),
            ]);

            $connection = OutboundConnection::query()->create([
                'name' => 'Partner AS2 Valid MDN Content-Type',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::As2,
                'is_active' => true,
                'settings' => [
                    'as2_url' => 'https://partner-as2.example/as2',
                    'as2_from' => 'tracepharma-as2-id',
                    'as2_to' => 'partner-as2-id',
                    'as2_mdn_ack_mode' => As2MdnAckMode::Sync->value,
                ],
            ]);
            $this->connectionId = (int) $connection->getKey();

            $result = app(As2OutboundSender::class)->send(
                $connection,
                '<?xml version="1.0"?><epcis:EPCISDocument xmlns:epcis="urn:epcglobal:epcis:xsd:1"></epcis:EPCISDocument>',
                'test-as2-valid-mdn-content-type.xml',
            );

            $this->assertSame($mdnBody, $result->mdnBody);
            $this->assertSame('received', $result->mdnStatus);
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function as2_transmit_sends_headers_and_persists_sync_mdn(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $mdnBody = "Reporting-UA: partner-as2.example\r\nOriginal-Message-ID: <test@tracepharma>\r\nDisposition: automatic-action/MDN-sent-automatically; processed";

            Http::fake([
                'https://partner-as2.example/as2' => Http::response($mdnBody, 200, [
                    'Content-Type' => 'multipart/report; report-type=disposition-notification',
                ]),
            ]);

            $connection = OutboundConnection::query()->create([
                'name' => 'Partner AS2',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::As2,
                'is_active' => true,
                'settings' => [
                    'as2_url' => 'https://partner-as2.example/as2',
                    'as2_from' => 'tracepharma-as2-id',
                    'as2_to' => 'partner-as2-id',
                    'as2_mdn_ack_mode' => As2MdnAckMode::Sync->value,
                    'disposition_notification_to' => 'https://tracepharma.example/as2/mdn',
                ],
            ]);
            $this->connectionId = (int) $connection->getKey();

            $path = 'epcis/outbound/test-as2-transmit-'.Str::uuid().'.xml';
            $xml = $this->schemaValidOutboundXml();
            Storage::disk('local')->put($path, $xml);

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'outbound',
                'format' => 'xml',
                'original_filename' => 'test-as2-transmit.xml',
                'payload_disk' => 'local',
                'payload_path' => $path,
                'file_sha256' => hash('sha256', $xml),
                'dscsa_affirm' => false,
                'status' => 'parsed',
                'reprocess_count' => 0,
                'event_count' => 1,
                'epc_count' => 0,
                'received_at' => now(),
                // Pin the AS2 connection — demo2 may already have another active outbound.
                'outbound_connection_id' => $connection->getKey(),
            ]);
            $this->documentId = (int) $document->getKey();

            app(OutboundEpcisTransmitter::class)->transmit($document->fresh());

            $document->refresh();
            $connection->refresh();

            $this->assertSame('sent', $document->transmission_status);
            $this->assertNotNull($document->sent_at);
            $this->assertSame($connection->getKey(), $document->outbound_connection_id);
            $this->assertNull($connection->last_error);
            $this->assertNotNull($connection->last_sent_at);

            Http::assertSent(function ($request): bool {
                return $request->url() === 'https://partner-as2.example/as2'
                    && $request->hasHeader('AS2-From', 'tracepharma-as2-id')
                    && $request->hasHeader('AS2-To', 'partner-as2-id')
                    && $request->hasHeader('Disposition-Notification-To', 'https://tracepharma.example/as2/mdn')
                    && filled($request->header('Message-ID'));
            });

            $mdn = TransmissionMdn::query()
                ->where('document_id', $document->getKey())
                ->first();

            $this->assertNotNull($mdn);
            $this->transmissionMdnIds[] = (int) $mdn->getKey();
            $this->assertSame('received', $mdn->mdn_status);
            $this->assertNotNull($mdn->mdn_received_at);
            $this->assertSame($mdnBody, data_get($mdn->mdn_payload, 'body'));
            $this->assertSame(200, data_get($mdn->mdn_payload, 'http_status'));
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function transmit_skips_mismatched_explicit_connection_pin(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Http::fake([
                'https://partner-as2.example/as2' => Http::response('', 200),
            ]);

            $partnerA = TradingPartner::query()->create([
                'name' => 'AS2 partner A '.uniqid(),
                'gln' => '0366159000010',
                'partner_type' => PartnerType::Pharmacy,
                'country_code' => 'US',
                'is_active' => true,
            ]);
            $this->partnerIds[] = (int) $partnerA->getKey();

            $partnerB = TradingPartner::query()->create([
                'name' => 'AS2 partner B '.uniqid(),
                'gln' => '0366159000027',
                'partner_type' => PartnerType::Pharmacy,
                'country_code' => 'US',
                'is_active' => true,
            ]);
            $this->partnerIds[] = (int) $partnerB->getKey();

            $connectionForB = OutboundConnection::query()->create([
                'name' => 'Partner B AS2',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::As2,
                'trading_partner_id' => $partnerB->getKey(),
                'is_active' => true,
                'settings' => [
                    'as2_url' => 'https://partner-as2.example/as2',
                    'as2_from' => 'tracepharma-as2-id',
                    'as2_to' => 'partner-as2-id',
                    'as2_mdn_ack_mode' => As2MdnAckMode::Sync->value,
                ],
            ]);
            $this->connectionId = (int) $connectionForB->getKey();

            $path = 'epcis/outbound/test-as2-mismatch-'.Str::uuid().'.xml';
            $xml = $this->schemaValidOutboundXml();
            Storage::disk('local')->put($path, $xml);

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'outbound',
                'format' => 'xml',
                'original_filename' => 'test-as2-mismatch.xml',
                'payload_disk' => 'local',
                'payload_path' => $path,
                'file_sha256' => hash('sha256', $xml),
                'dscsa_affirm' => false,
                'status' => 'parsed',
                'reprocess_count' => 0,
                'event_count' => 1,
                'epc_count' => 0,
                'received_at' => now(),
                'trading_partner_id' => $partnerA->getKey(),
                'outbound_connection_id' => $connectionForB->getKey(),
            ]);
            $this->documentId = (int) $document->getKey();

            app(OutboundEpcisTransmitter::class)->transmit($document->fresh());

            $document->refresh();

            $this->assertSame('skipped', $document->transmission_status);
            $this->assertSame($connectionForB->getKey(), $document->outbound_connection_id);
            Http::assertNothingSent();
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function second_transmit_on_sent_document_does_not_resend(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $mdnBody = "Reporting-UA: partner-as2.example\r\nOriginal-Message-ID: <test@tracepharma>\r\nDisposition: automatic-action/MDN-sent-automatically; processed";

            Http::fake([
                'https://partner-as2.example/as2' => Http::response($mdnBody, 200, [
                    'Content-Type' => 'multipart/report; report-type=disposition-notification',
                ]),
            ]);

            $connection = OutboundConnection::query()->create([
                'name' => 'Partner AS2 idempotent',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::As2,
                'is_active' => true,
                'settings' => [
                    'as2_url' => 'https://partner-as2.example/as2',
                    'as2_from' => 'tracepharma-as2-id',
                    'as2_to' => 'partner-as2-id',
                    'as2_mdn_ack_mode' => As2MdnAckMode::Sync->value,
                ],
            ]);
            $this->connectionId = (int) $connection->getKey();

            $path = 'epcis/outbound/test-as2-idempotent-'.Str::uuid().'.xml';
            $xml = $this->schemaValidOutboundXml();
            Storage::disk('local')->put($path, $xml);

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'outbound',
                'format' => 'xml',
                'original_filename' => 'test-as2-idempotent.xml',
                'payload_disk' => 'local',
                'payload_path' => $path,
                'file_sha256' => hash('sha256', $xml),
                'dscsa_affirm' => false,
                'status' => 'parsed',
                'reprocess_count' => 0,
                'event_count' => 1,
                'epc_count' => 0,
                'received_at' => now(),
                'outbound_connection_id' => $connection->getKey(),
            ]);
            $this->documentId = (int) $document->getKey();

            $transmitter = app(OutboundEpcisTransmitter::class);
            $transmitter->transmit($document->fresh());
            $transmitter->transmit($document->fresh());

            $document->refresh();
            $this->assertSame('sent', $document->transmission_status);

            Http::assertSentCount(1);
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function as2_async_mdn_webhook_marks_transmission_mdn_received(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $capturedMessageId = null;

            Http::fake([
                'https://partner-as2.example/as2' => Http::response('', 200),
            ]);

            $connection = OutboundConnection::query()->create([
                'name' => 'Partner AS2 Async MDN',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::As2,
                'is_active' => true,
                'settings' => [
                    'as2_url' => 'https://partner-as2.example/as2',
                    'as2_from' => 'tracepharma-as2-id',
                    'as2_to' => 'partner-as2-id',
                    'as2_mdn_ack_mode' => As2MdnAckMode::Async->value,
                    'disposition_notification_to' => 'https://tracepharma.example/as2/mdn',
                ],
                'credentials' => [
                    'as2_mdn_webhook_secret' => self::AS2_MDN_WEBHOOK_SECRET,
                ],
            ]);
            $this->connectionId = (int) $connection->getKey();

            $path = 'epcis/outbound/test-as2-async-'.Str::uuid().'.xml';
            $xml = $this->schemaValidOutboundXml();
            Storage::disk('local')->put($path, $xml);

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'outbound',
                'format' => 'xml',
                'original_filename' => 'test-as2-async.xml',
                'payload_disk' => 'local',
                'payload_path' => $path,
                'file_sha256' => hash('sha256', $xml),
                'dscsa_affirm' => false,
                'status' => 'parsed',
                'reprocess_count' => 0,
                'event_count' => 1,
                'epc_count' => 0,
                'received_at' => now(),
                'outbound_connection_id' => $connection->getKey(),
            ]);
            $this->documentId = (int) $document->getKey();

            app(OutboundEpcisTransmitter::class)->transmit($document->fresh());

            Http::assertSent(function ($request) use (&$capturedMessageId): bool {
                $messageId = $request->header('Message-ID')[0] ?? null;

                if (is_string($messageId) && $messageId !== '') {
                    $capturedMessageId = $messageId;
                }

                return true;
            });

            $this->assertNotNull($capturedMessageId);

            $mdn = TransmissionMdn::query()
                ->where('document_id', $document->getKey())
                ->first();

            $this->assertNotNull($mdn);
            $this->transmissionMdnIds[] = (int) $mdn->getKey();
            $this->assertSame('pending', $mdn->mdn_status);
            $this->assertSame($capturedMessageId, data_get($mdn->mdn_payload, 'message_id'));

            $asyncMdnBody = "Reporting-UA: partner-as2.example\r\nOriginal-Message-ID: {$capturedMessageId}\r\nDisposition: automatic-action/MDN-sent-automatically; processed";

            $response = $this->postAsyncMdnWebhook(
                tenant: $tenant,
                connection: $connection,
                body: $asyncMdnBody,
            );

            $response->assertOk()
                ->assertJson([
                    'status' => 'received',
                    'transmission_mdn_id' => $mdn->getKey(),
                ]);

            $mdn->refresh();
            $this->assertSame('received', $mdn->mdn_status);
            $this->assertNotNull($mdn->mdn_received_at);
            $this->assertTrue(data_get($mdn->mdn_payload, 'async_webhook'));
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function as2_async_mdn_webhook_rejects_unauthenticated_requests(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$connection, $document, $mdn, $capturedMessageId] = $this->transmitAsyncMdnFixture($tenant);

            $asyncMdnBody = "Reporting-UA: partner-as2.example\r\nOriginal-Message-ID: {$capturedMessageId}\r\nDisposition: automatic-action/MDN-sent-automatically; processed";

            $this->call(
                'POST',
                "/api/webhooks/as2/mdn/{$tenant->getKey()}/{$connection->getKey()}",
                content: $asyncMdnBody,
                server: ['CONTENT_TYPE' => 'multipart/report; report-type=disposition-notification'],
            )->assertUnauthorized();

            $mdn->refresh();
            $this->assertSame('pending', $mdn->mdn_status);
            $this->assertNull($mdn->mdn_received_at);
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function as2_async_mdn_webhook_marks_failed_disposition(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$connection, $document, $mdn, $capturedMessageId] = $this->transmitAsyncMdnFixture($tenant);

            $asyncMdnBody = "Reporting-UA: partner-as2.example\r\nOriginal-Message-ID: {$capturedMessageId}\r\nDisposition: automatic-action/MDN-sent-automatically; failed/failure: authentication-failed";

            $response = $this->postAsyncMdnWebhook(
                tenant: $tenant,
                connection: $connection,
                body: $asyncMdnBody,
            );

            $response->assertOk()
                ->assertJson([
                    'status' => 'failed',
                    'transmission_mdn_id' => $mdn->getKey(),
                ]);

            $mdn->refresh();
            $this->assertSame('failed', $mdn->mdn_status);
            $this->assertNotNull($mdn->mdn_received_at);
            $this->assertSame(
                'automatic-action/MDN-sent-automatically; failed/failure: authentication-failed',
                data_get($mdn->mdn_payload, 'disposition'),
            );
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function as2_async_mdn_unparseable_disposition_stays_pending_so_good_mdn_can_succeed(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$connection, $document, $mdn, $capturedMessageId] = $this->transmitAsyncMdnFixture($tenant);

            $unparseableBody = "Reporting-UA: partner-as2.example\r\nOriginal-Message-ID: {$capturedMessageId}\r\n";

            $this->postAsyncMdnWebhook(
                tenant: $tenant,
                connection: $connection,
                body: $unparseableBody,
            )->assertOk()
                ->assertJson([
                    'status' => 'received-unknown',
                    'transmission_mdn_id' => $mdn->getKey(),
                ]);

            $mdn->refresh();
            $this->assertSame('pending', $mdn->mdn_status);
            $this->assertNull($mdn->mdn_received_at);
            $this->assertTrue(data_get($mdn->mdn_payload, 'disposition_unparseable'));

            $goodBody = "Reporting-UA: partner-as2.example\r\nOriginal-Message-ID: {$capturedMessageId}\r\nDisposition: automatic-action/MDN-sent-automatically; processed";

            $this->postAsyncMdnWebhook(
                tenant: $tenant,
                connection: $connection,
                body: $goodBody,
            )->assertOk()
                ->assertJson([
                    'status' => 'received',
                    'transmission_mdn_id' => $mdn->getKey(),
                ]);

            $mdn->refresh();
            $this->assertSame('received', $mdn->mdn_status);
            $this->assertNotNull($mdn->mdn_received_at);
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function as2_async_mdn_webhook_rejects_replay_of_finalized_mdn(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$connection, $document, $mdn, $capturedMessageId] = $this->transmitAsyncMdnFixture($tenant);

            $asyncMdnBody = "Reporting-UA: partner-as2.example\r\nOriginal-Message-ID: {$capturedMessageId}\r\nDisposition: automatic-action/MDN-sent-automatically; processed";

            $this->postAsyncMdnWebhook(
                tenant: $tenant,
                connection: $connection,
                body: $asyncMdnBody,
            )->assertOk();

            $this->postAsyncMdnWebhook(
                tenant: $tenant,
                connection: $connection,
                body: $asyncMdnBody,
            )->assertConflict();
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function sync_mdn_defaults_to_failed_when_disposition_is_unparseable(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $mdnBody = "Reporting-UA: partner-as2.example\r\nOriginal-Message-ID: <test@tracepharma>\r\n";

            Http::fake([
                'https://partner-as2.example/as2' => Http::response($mdnBody, 200, [
                    'Content-Type' => 'multipart/report; report-type=disposition-notification',
                ]),
            ]);

            $connection = OutboundConnection::query()->create([
                'name' => 'Partner AS2 Unparseable MDN',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::As2,
                'is_active' => true,
                'settings' => [
                    'as2_url' => 'https://partner-as2.example/as2',
                    'as2_from' => 'tracepharma-as2-id',
                    'as2_to' => 'partner-as2-id',
                    'as2_mdn_ack_mode' => As2MdnAckMode::Sync->value,
                ],
            ]);
            $this->connectionId = (int) $connection->getKey();

            $result = app(As2OutboundSender::class)->send(
                $connection,
                '<?xml version="1.0"?><epcis:EPCISDocument xmlns:epcis="urn:epcglobal:epcis:xsd:1"></epcis:EPCISDocument>',
                'test-as2-unparseable-mdn.xml',
            );

            $this->assertSame('failed', $result->mdnStatus);
            $this->assertSame($mdnBody, $result->mdnBody);
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function as2_async_mdn_webhook_redacts_sensitive_headers_before_persist(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$connection, $document, $mdn, $capturedMessageId] = $this->transmitAsyncMdnFixture($tenant);

            $asyncMdnBody = "Reporting-UA: partner-as2.example\r\nOriginal-Message-ID: {$capturedMessageId}\r\nDisposition: automatic-action/MDN-sent-automatically; processed";

            $this->call(
                'POST',
                "/api/webhooks/as2/mdn/{$tenant->getKey()}/{$connection->getKey()}",
                content: $asyncMdnBody,
                server: [
                    'CONTENT_TYPE' => 'multipart/report; report-type=disposition-notification',
                    'HTTP_AUTHORIZATION' => 'Bearer super-secret-token',
                    'HTTP_X_AS2_MDN_SECRET' => self::AS2_MDN_WEBHOOK_SECRET,
                ],
            )->assertOk();

            $mdn->refresh();
            $headers = data_get($mdn->mdn_payload, 'headers', []);

            $this->assertSame(['[REDACTED]'], data_get($headers, 'authorization'));
            $this->assertSame(['[REDACTED]'], data_get($headers, 'x-as2-mdn-secret'));
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function force_retransmit_supersedes_pending_transmission_mdns(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$connection, $document, $pendingMdn, $capturedMessageId] = $this->transmitAsyncMdnFixture($tenant);

            Http::fake([
                'https://partner-as2.example/as2' => Http::response('', 200),
            ]);

            app(OutboundEpcisTransmitter::class)->transmit($document->fresh(), forceRetransmit: true);

            $pendingMdn->refresh();
            $this->assertSame('superseded', $pendingMdn->mdn_status);
            $this->assertNotNull($pendingMdn->mdn_received_at);

            $latestMdn = TransmissionMdn::query()
                ->where('document_id', $document->getKey())
                ->orderByDesc('id')
                ->first();

            $this->assertNotNull($latestMdn);
            $this->transmissionMdnIds[] = (int) $latestMdn->getKey();
            $this->assertNotSame($pendingMdn->getKey(), $latestMdn->getKey());
            $this->assertSame('pending', $latestMdn->mdn_status);
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function as2_transmit_marks_sync_mdn_failed_when_disposition_reports_failure(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $mdnBody = "Reporting-UA: partner-as2.example\r\nOriginal-Message-ID: <test@tracepharma>\r\nDisposition: automatic-action/MDN-sent-automatically; failed/failure: decryption-failed";

            Http::fake([
                'https://partner-as2.example/as2' => Http::response($mdnBody, 200, [
                    'Content-Type' => 'multipart/report; report-type=disposition-notification',
                ]),
            ]);

            $connection = OutboundConnection::query()->create([
                'name' => 'Partner AS2 Failed MDN',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::As2,
                'is_active' => true,
                'settings' => [
                    'as2_url' => 'https://partner-as2.example/as2',
                    'as2_from' => 'tracepharma-as2-id',
                    'as2_to' => 'partner-as2-id',
                    'as2_mdn_ack_mode' => As2MdnAckMode::Sync->value,
                ],
            ]);
            $this->connectionId = (int) $connection->getKey();

            $result = app(As2OutboundSender::class)->send($connection, '<?xml version="1.0"?><epcis:EPCISDocument xmlns:epcis="urn:epcglobal:epcis:xsd:1"></epcis:EPCISDocument>', 'test-as2-failed-mdn.xml');

            $this->assertSame('failed', $result->mdnStatus);
            $this->assertFalse($result->marksDocumentSent());
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function sync_failed_mdn_marks_document_failed_not_sent(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $mdnBody = "Reporting-UA: partner-as2.example\r\nOriginal-Message-ID: <test@tracepharma>\r\nDisposition: automatic-action/MDN-sent-automatically; failed/failure: decryption-failed";

            Http::fake([
                'https://partner-as2.example/as2' => Http::response($mdnBody, 200, [
                    'Content-Type' => 'multipart/report; report-type=disposition-notification',
                ]),
            ]);

            $connection = OutboundConnection::query()->create([
                'name' => 'Partner AS2 Failed MDN Transmit',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::As2,
                'is_active' => true,
                'settings' => [
                    'as2_url' => 'https://partner-as2.example/as2',
                    'as2_from' => 'tracepharma-as2-id',
                    'as2_to' => 'partner-as2-id',
                    'as2_mdn_ack_mode' => As2MdnAckMode::Sync->value,
                ],
            ]);
            $this->connectionId = (int) $connection->getKey();

            $path = 'epcis/outbound/test-as2-failed-mdn-transmit-'.Str::uuid().'.xml';
            $xml = $this->schemaValidOutboundXml();
            Storage::disk('local')->put($path, $xml);

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'outbound',
                'format' => 'xml',
                'original_filename' => 'test-as2-failed-mdn-transmit.xml',
                'payload_disk' => 'local',
                'payload_path' => $path,
                'file_sha256' => hash('sha256', $xml),
                'dscsa_affirm' => false,
                'status' => 'parsed',
                'reprocess_count' => 0,
                'event_count' => 1,
                'epc_count' => 0,
                'received_at' => now(),
                'outbound_connection_id' => $connection->getKey(),
            ]);
            $this->documentId = (int) $document->getKey();

            app(OutboundEpcisTransmitter::class)->transmit($document->fresh());

            $document->refresh();
            $connection->refresh();

            $this->assertSame('failed', $document->transmission_status);
            $this->assertNull($document->sent_at);
            $this->assertStringContainsString('MDN failed', (string) $document->error_message);
            $this->assertNotNull($connection->last_error);

            $mdn = TransmissionMdn::query()
                ->where('document_id', $document->getKey())
                ->first();

            $this->assertNotNull($mdn);
            $this->transmissionMdnIds[] = (int) $mdn->getKey();
            $this->assertSame('failed', $mdn->mdn_status);
            $this->assertNotNull($mdn->mdn_received_at);
            $this->assertSame($mdnBody, data_get($mdn->mdn_payload, 'body'));
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    /**
     * @return array{0: OutboundConnection, 1: EpcisDocument, 2: TransmissionMdn, 3: string}
     */
    private function transmitAsyncMdnFixture(Tenant $tenant): array
    {
        $capturedMessageId = null;

        Http::fake([
            'https://partner-as2.example/as2' => Http::response('', 200),
        ]);

        $connection = OutboundConnection::query()->create([
            'name' => 'Partner AS2 Async MDN',
            'serialization_provider' => SerializationProvider::CustomHttps,
            'transport' => OutboundTransport::As2,
            'is_active' => true,
            'settings' => [
                'as2_url' => 'https://partner-as2.example/as2',
                'as2_from' => 'tracepharma-as2-id',
                'as2_to' => 'partner-as2-id',
                'as2_mdn_ack_mode' => As2MdnAckMode::Async->value,
                'disposition_notification_to' => 'https://tracepharma.example/as2/mdn',
            ],
            'credentials' => [
                'as2_mdn_webhook_secret' => self::AS2_MDN_WEBHOOK_SECRET,
            ],
        ]);
        $this->connectionId = (int) $connection->getKey();

        $path = 'epcis/outbound/test-as2-async-'.Str::uuid().'.xml';
        $xml = $this->schemaValidOutboundXml();
        Storage::disk('local')->put($path, $xml);

        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'direction' => 'outbound',
            'format' => 'xml',
            'original_filename' => 'test-as2-async.xml',
            'payload_disk' => 'local',
            'payload_path' => $path,
            'file_sha256' => hash('sha256', $xml),
            'dscsa_affirm' => false,
            'status' => 'parsed',
            'reprocess_count' => 0,
            'event_count' => 1,
            'epc_count' => 0,
            'received_at' => now(),
            'outbound_connection_id' => $connection->getKey(),
        ]);
        $this->documentId = (int) $document->getKey();

        app(OutboundEpcisTransmitter::class)->transmit($document->fresh());

        Http::assertSent(function ($request) use (&$capturedMessageId): bool {
            $messageId = $request->header('Message-ID')[0] ?? null;

            if (is_string($messageId) && $messageId !== '') {
                $capturedMessageId = $messageId;
            }

            return true;
        });

        $this->assertNotNull($capturedMessageId);

        $mdn = TransmissionMdn::query()
            ->where('document_id', $document->getKey())
            ->first();

        $this->assertNotNull($mdn);
        $this->transmissionMdnIds[] = (int) $mdn->getKey();
        $this->assertSame('pending', $mdn->mdn_status);
        $this->assertSame($capturedMessageId, data_get($mdn->mdn_payload, 'message_id'));

        return [$connection, $document, $mdn, $capturedMessageId];
    }

    private function postAsyncMdnWebhook(Tenant $tenant, OutboundConnection $connection, string $body): TestResponse
    {
        return $this->call(
            'POST',
            "/api/webhooks/as2/mdn/{$tenant->getKey()}/{$connection->getKey()}",
            content: $body,
            server: [
                'CONTENT_TYPE' => 'multipart/report; report-type=disposition-notification',
                'HTTP_X_AS2_MDN_SECRET' => self::AS2_MDN_WEBHOOK_SECRET,
            ],
        );
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

    private function schemaValidOutboundXml(): string
    {
        $xml = file_get_contents(base_path('tests/Fixtures/epcis/minimal_object_shipping.xml'));
        $this->assertNotFalse($xml);

        return str_replace(
            '11111111-2222-3333-4444-555555555555',
            (string) Str::uuid(),
            $xml,
        );
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->transmissionMdnIds !== []) {
            TransmissionMdn::query()->whereIn('id', $this->transmissionMdnIds)->delete();
            $this->transmissionMdnIds = [];
        }

        if ($this->documentId !== null) {
            TransmissionMdn::query()->where('document_id', $this->documentId)->delete();
            EpcisDocument::query()->whereKey($this->documentId)->delete();
            $this->documentId = null;
        }

        if ($this->connectionId !== null) {
            OutboundConnection::query()->whereKey($this->connectionId)->delete();
            $this->connectionId = null;
        }

        if ($this->partnerIds !== []) {
            TradingPartner::query()->whereIn('id', $this->partnerIds)->delete();
            $this->partnerIds = [];
        }
    }
}
