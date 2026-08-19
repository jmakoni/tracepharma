<?php

namespace Tests\Feature\Integrations;

use App\Enums\InboundTransport;
use App\Enums\SerializationProvider;
use App\Enums\TenantProfile;
use App\Filament\App\Pages\ApiTokens;
use App\Filament\App\Resources\InboundConnections\InboundConnectionResource;
use App\Http\Controllers\Webhooks\EpcisInboundWebhookController;
use App\Models\InboundConnection;
use App\Models\Tenant;
use App\Support\TenantFeatures;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CleansDemo2EpcisArtifacts;
use Tests\TestCase;

class InboundConnectionIntegrationTest extends TestCase
{
    use CleansDemo2EpcisArtifacts;

    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    #[Test]
    public function inbound_connection_resource_is_gated_by_tenant_features(): void
    {
        $this->assertTrue((new TenantFeatures(TenantProfile::Pharmacy))->supportsInboundIntegrations());
        $this->assertFalse((new TenantFeatures(TenantProfile::BuyingGroup))->supportsInboundIntegrations());
    }

    #[Test]
    public function pharmacy_tenant_can_create_https_inbound_connection(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $connection = InboundConnection::query()->create([
                'name' => 'Cardinal HTTPS',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => InboundTransport::Https,
                'is_active' => true,
                'credentials' => ['webhook_token' => 'test-token-123'],
            ]);
            $this->trackInboundConnectionId((int) $connection->id);

            $this->assertDatabaseHas('inbound_connections', [
                'id' => $connection->id,
                'name' => 'Cardinal HTTPS',
                'transport' => InboundTransport::Https->value,
            ]);
            $this->assertNotEmpty($connection->inbound_token);
            $this->assertStringContainsString((string) $tenant->id, (string) $connection->webhookUrl());
        } finally {
            $this->cleanupTrackedEpcisArtifacts();
            tenancy()->end();
        }
    }

    #[Test]
    public function webhook_accepts_epcis_xml_and_queues_processing(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $connection = InboundConnection::query()->create([
                'name' => 'Webhook Test',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => InboundTransport::Https,
                'is_active' => true,
            ]);
            $this->trackInboundConnectionId((int) $connection->id);

            $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
            $this->assertFileExists($fixture);
            $xml = file_get_contents($fixture);
            $this->assertNotFalse($xml);
            $xml = str_replace('11111111-2222-3333-4444-555555555555', (string) str()->uuid(), $xml);

            $request = Request::create(
                uri: '/api/webhooks/epcis/'.$tenant->id.'/'.$connection->id,
                method: 'POST',
                content: $xml,
                server: [
                    'HTTP_X_INBOUND_TOKEN' => $connection->inbound_token,
                    'HTTP_X_ORIGINAL_FILENAME' => 'webhook-test.xml',
                    'CONTENT_TYPE' => 'application/xml',
                ],
            );

            $response = app(EpcisInboundWebhookController::class)->handle(
                $request,
                tenantId: $tenant->id,
                connectionId: (int) $connection->id,
            );

            $this->assertSame(202, $response->getStatusCode());
            $payload = $response->getData(true);
            $this->assertArrayHasKey('document_id', $payload);
            $this->trackEpcisDocumentId((int) $payload['document_id']);
            $this->assertContains($payload['status'], ['received', 'validated', 'processed']);

            $this->assertDatabaseHas('epcis_documents', [
                'id' => $payload['document_id'],
                'inbound_connection_id' => $connection->id,
            ]);
        } finally {
            $this->cleanupTrackedEpcisArtifacts();
            tenancy()->end();
        }
    }

    #[Test]
    public function api_tokens_page_requires_inbound_integrations_and_elevated_role(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->assertTrue(InboundConnectionResource::canAccess());

            $tenant = tenant();
            $original = $tenant->profile;
            $tenant->setAttribute('profile', TenantProfile::BuyingGroup);
            $this->assertFalse(ApiTokens::canAccess());
            $tenant->setAttribute('profile', $original);

            $this->assertFalse(ApiTokens::canAccess());
        } finally {
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
}
