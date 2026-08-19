<?php

namespace Tests\Feature\Integrations;

use App\Enums\OutboundTransport;
use App\Enums\SerializationProvider;
use App\Enums\TenantProfile;
use App\Models\Epcis\EpcisDocument;
use App\Models\OutboundConnection;
use App\Models\Tenant;
use App\Services\Epcis\Contracts\OutboundEpcisTransmitter;
use App\Support\Integrations\OutboundTransportAvailability;
use App\Support\TenantFeatures;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OutboundConnectionIntegrationTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?int $connectionId = null;

    private ?int $documentId = null;

    /** @var list<int> */
    private array $connectionIds = [];

    /** @var list<int> */
    private array $deactivatedOutboundConnectionIds = [];

    #[Test]
    public function outbound_connection_resource_is_gated_by_tenant_features(): void
    {
        $this->assertTrue((new TenantFeatures(TenantProfile::DrugWholesaler))->supportsOutboundIntegrations());
        $this->assertFalse((new TenantFeatures(TenantProfile::Pharmacy))->supportsOutboundIntegrations());
    }

    #[Test]
    public function https_transmit_marks_document_sent(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Http::fake([
                'https://partner.example/epcis' => Http::response('OK', 202),
            ]);

            $connection = OutboundConnection::query()->create([
                'name' => 'Partner HTTPS',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::Https,
                'is_active' => true,
                'settings' => ['endpoint_url' => 'https://partner.example/epcis'],
                'credentials' => ['webhook_token' => 'outbound-token'],
            ]);
            $this->connectionId = (int) $connection->getKey();

            $path = 'epcis/outbound/test-transmit-'.Str::uuid().'.xml';
            $xml = '<?xml version="1.0"?><epcis:EPCISDocument xmlns:epcis="urn:epcglobal:epcis:xsd:1"></epcis:EPCISDocument>';
            Storage::disk('local')->put($path, $xml);

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'outbound',
                'format' => 'xml',
                'original_filename' => 'test-transmit.xml',
                'payload_disk' => 'local',
                'payload_path' => $path,
                'file_sha256' => hash('sha256', $xml),
                'dscsa_affirm' => false,
                'status' => 'parsed',
                'reprocess_count' => 0,
                'event_count' => 1,
                'epc_count' => 0,
                'received_at' => now(),
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
                return $request->url() === 'https://partner.example/epcis'
                    && $request->hasHeader('X-Inbound-Token', 'outbound-token');
            });
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function transmit_honors_documents_explicit_outbound_connection_over_resolver_default(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Http::fake([
                'https://default.example/epcis' => Http::response('OK', 202),
                'https://explicit.example/epcis' => Http::response('OK', 202),
            ]);

            // Lower id => resolver's default pick when no trading partner is specified.
            $defaultConnection = OutboundConnection::query()->create([
                'name' => 'Default HTTPS',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::Https,
                'is_active' => true,
                'settings' => ['endpoint_url' => 'https://default.example/epcis'],
                'credentials' => ['webhook_token' => 'default-token'],
            ]);
            $this->connectionIds[] = (int) $defaultConnection->getKey();

            $explicitConnection = OutboundConnection::query()->create([
                'name' => 'Explicit HTTPS',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::Https,
                'is_active' => true,
                'settings' => ['endpoint_url' => 'https://explicit.example/epcis'],
                'credentials' => ['webhook_token' => 'explicit-token'],
            ]);
            $this->connectionIds[] = (int) $explicitConnection->getKey();

            $path = 'epcis/outbound/test-explicit-transmit-'.Str::uuid().'.xml';
            $xml = '<?xml version="1.0"?><epcis:EPCISDocument xmlns:epcis="urn:epcglobal:epcis:xsd:1"></epcis:EPCISDocument>';
            Storage::disk('local')->put($path, $xml);

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'outbound',
                'format' => 'xml',
                'original_filename' => 'test-explicit-transmit.xml',
                'payload_disk' => 'local',
                'payload_path' => $path,
                'file_sha256' => hash('sha256', $xml),
                'dscsa_affirm' => false,
                'status' => 'parsed',
                'reprocess_count' => 0,
                'event_count' => 1,
                'epc_count' => 0,
                'received_at' => now(),
                'outbound_connection_id' => $explicitConnection->getKey(),
            ]);
            $this->documentId = (int) $document->getKey();

            app(OutboundEpcisTransmitter::class)->transmit($document->fresh());

            $document->refresh();
            $defaultConnection->refresh();
            $explicitConnection->refresh();

            $this->assertSame('sent', $document->transmission_status);
            $this->assertSame($explicitConnection->getKey(), $document->outbound_connection_id);
            $this->assertNotNull($explicitConnection->last_sent_at);
            $this->assertNull($defaultConnection->last_sent_at);

            Http::assertSent(fn ($request): bool => $request->url() === 'https://explicit.example/epcis');
            Http::assertNotSent(fn ($request): bool => $request->url() === 'https://default.example/epcis');
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function transmit_skips_inactive_explicit_connection_pin(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Http::fake([
                'https://default.example/epcis' => Http::response('OK', 202),
                'https://inactive-pinned.example/epcis' => Http::response('OK', 202),
            ]);

            $defaultConnection = OutboundConnection::query()->create([
                'name' => 'Default HTTPS',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::Https,
                'is_active' => true,
                'settings' => ['endpoint_url' => 'https://default.example/epcis'],
                'credentials' => ['webhook_token' => 'default-token'],
            ]);
            $this->connectionIds[] = (int) $defaultConnection->getKey();

            $inactiveConnection = OutboundConnection::query()->create([
                'name' => 'Inactive Pinned HTTPS',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::Https,
                'is_active' => false,
                'settings' => ['endpoint_url' => 'https://inactive-pinned.example/epcis'],
                'credentials' => ['webhook_token' => 'inactive-pinned-token'],
            ]);
            $this->connectionIds[] = (int) $inactiveConnection->getKey();

            $path = 'epcis/outbound/test-inactive-pin-'.Str::uuid().'.xml';
            $xml = '<?xml version="1.0"?><epcis:EPCISDocument xmlns:epcis="urn:epcglobal:epcis:xsd:1"></epcis:EPCISDocument>';
            Storage::disk('local')->put($path, $xml);

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'outbound',
                'format' => 'xml',
                'original_filename' => 'test-inactive-pin.xml',
                'payload_disk' => 'local',
                'payload_path' => $path,
                'file_sha256' => hash('sha256', $xml),
                'dscsa_affirm' => false,
                'status' => 'parsed',
                'reprocess_count' => 0,
                'event_count' => 1,
                'epc_count' => 0,
                'received_at' => now(),
                'outbound_connection_id' => $inactiveConnection->getKey(),
            ]);
            $this->documentId = (int) $document->getKey();

            app(OutboundEpcisTransmitter::class)->transmit($document->fresh());

            $document->refresh();

            $this->assertSame('skipped', $document->transmission_status);
            $this->assertSame($inactiveConnection->getKey(), $document->outbound_connection_id);
            Http::assertNothingSent();
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function legacy_sftp_connection_cannot_be_created(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->expectException(\Illuminate\Validation\ValidationException::class);

            OutboundConnection::query()->create([
                'name' => 'Blocked SFTP',
                'serialization_provider' => SerializationProvider::TraceLink,
                'transport' => OutboundTransport::Sftp,
                'is_active' => true,
                'settings' => [
                    'host' => 'sftp.example',
                    'outbound_path' => '/outbound/epcis',
                ],
                'credentials' => ['username' => 'legacy'],
            ]);
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function legacy_sftp_transmit_fails_closed_with_clear_error(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Http::fake();

            $connection = OutboundConnection::withoutEvents(fn () => OutboundConnection::query()->create([
                'name' => 'Legacy SFTP',
                'serialization_provider' => SerializationProvider::TraceLink,
                'transport' => OutboundTransport::Sftp,
                'is_active' => true,
                'settings' => [
                    'host' => 'sftp.example',
                    'outbound_path' => '/outbound/epcis',
                ],
                'credentials' => ['username' => 'legacy'],
            ]));
            $this->connectionId = (int) $connection->getKey();

            $path = 'epcis/outbound/test-legacy-sftp-'.Str::uuid().'.xml';
            $xml = '<?xml version="1.0"?><epcis:EPCISDocument xmlns:epcis="urn:epcglobal:epcis:xsd:1"></epcis:EPCISDocument>';
            Storage::disk('local')->put($path, $xml);

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'outbound',
                'format' => 'xml',
                'original_filename' => 'test-legacy-sftp.xml',
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
            $this->assertSame($connection->getKey(), $document->outbound_connection_id);
            $this->assertSame(OutboundTransportAvailability::sftpTransmitMessage(), $document->error_message);
            $this->assertSame(OutboundTransportAvailability::sftpTransmitMessage(), $connection->last_error);

            Http::assertNothingSent();
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function legacy_sftp_connection_is_not_selected_by_resolver(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Http::fake([
                'https://https-fallback.example/epcis' => Http::response('OK', 202),
            ]);

            $this->deactivatedOutboundConnectionIds = OutboundConnection::query()
                ->where('is_active', true)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            OutboundConnection::query()->update(['is_active' => false]);

            OutboundConnection::withoutEvents(fn () => OutboundConnection::query()->create([
                'name' => 'Legacy SFTP only',
                'serialization_provider' => SerializationProvider::TraceLink,
                'transport' => OutboundTransport::Sftp,
                'is_active' => true,
                'settings' => [
                    'host' => 'sftp.example',
                    'outbound_path' => '/outbound/epcis',
                ],
            ]));
            $this->connectionIds[] = (int) OutboundConnection::query()->where('name', 'Legacy SFTP only')->value('id');

            $httpsConnection = OutboundConnection::query()->create([
                'name' => 'HTTPS fallback',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::Https,
                'is_active' => true,
                'settings' => ['endpoint_url' => 'https://https-fallback.example/epcis'],
            ]);
            $this->connectionIds[] = (int) $httpsConnection->getKey();

            $path = 'epcis/outbound/test-resolver-skip-sftp-'.Str::uuid().'.xml';
            $xml = '<?xml version="1.0"?><epcis:EPCISDocument xmlns:epcis="urn:epcglobal:epcis:xsd:1"></epcis:EPCISDocument>';
            Storage::disk('local')->put($path, $xml);

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'outbound',
                'format' => 'xml',
                'original_filename' => 'test-resolver-skip-sftp.xml',
                'payload_disk' => 'local',
                'payload_path' => $path,
                'file_sha256' => hash('sha256', $xml),
                'dscsa_affirm' => false,
                'status' => 'parsed',
                'reprocess_count' => 0,
                'event_count' => 1,
                'epc_count' => 0,
                'received_at' => now(),
            ]);
            $this->documentId = (int) $document->getKey();

            app(OutboundEpcisTransmitter::class)->transmit($document->fresh());

            $document->refresh();

            $this->assertSame('sent', $document->transmission_status);
            $this->assertSame($httpsConnection->getKey(), $document->outbound_connection_id);

            Http::assertSent(fn ($request): bool => $request->url() === 'https://https-fallback.example/epcis');
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function missing_payload_file_marks_failed_and_keeps_explicit_outbound_connection(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Http::fake();

            $explicitConnection = OutboundConnection::query()->create([
                'name' => 'Pinned HTTPS',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::Https,
                'is_active' => true,
                'settings' => ['endpoint_url' => 'https://pinned.example/epcis'],
                'credentials' => ['webhook_token' => 'pinned-token'],
            ]);
            $this->connectionIds[] = (int) $explicitConnection->getKey();

            // Payload path is set but the file is gone — fail closed, do not skip/cancel.
            $missingPath = 'epcis/outbound/missing-'.Str::uuid().'.xml';
            $this->assertFalse(Storage::disk('local')->exists($missingPath));

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'outbound',
                'format' => 'xml',
                'original_filename' => 'missing.xml',
                'payload_disk' => 'local',
                'payload_path' => $missingPath,
                'file_sha256' => hash('sha256', 'missing'),
                'dscsa_affirm' => false,
                'status' => 'parsed',
                'reprocess_count' => 0,
                'event_count' => 1,
                'epc_count' => 0,
                'received_at' => now(),
                'outbound_connection_id' => $explicitConnection->getKey(),
            ]);
            $this->documentId = (int) $document->getKey();

            try {
                app(OutboundEpcisTransmitter::class)->transmit($document->fresh());
                $this->fail('Expected RuntimeException when the payload path is set but the file is missing.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('EPCIS payload file is missing', $e->getMessage());
            }

            $document->refresh();

            $this->assertSame('failed', $document->transmission_status);
            $this->assertNull($document->sent_at);
            $this->assertSame($explicitConnection->getKey(), $document->outbound_connection_id);
            $this->assertStringContainsString('EPCIS payload file is missing', (string) $document->error_message);

            Http::assertNothingSent();
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
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
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->documentId !== null) {
            EpcisDocument::query()->whereKey($this->documentId)->delete();
            $this->documentId = null;
        }

        if ($this->connectionId !== null) {
            OutboundConnection::query()->whereKey($this->connectionId)->delete();
            $this->connectionId = null;
        }

        if ($this->connectionIds !== []) {
            OutboundConnection::query()->whereIn('id', $this->connectionIds)->delete();
            $this->connectionIds = [];
        }

        if ($this->deactivatedOutboundConnectionIds !== []) {
            OutboundConnection::query()
                ->whereIn('id', $this->deactivatedOutboundConnectionIds)
                ->update(['is_active' => true]);
            $this->deactivatedOutboundConnectionIds = [];
        }
    }
}
