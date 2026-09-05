<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Enums\InboundTransport;
use App\Enums\OutboundTransport;
use App\Enums\SerializationProvider;
use App\Enums\TenantProfile;
use App\Models\InboundConnection;
use App\Models\OutboundConnection;
use App\Models\Tenant;
use App\Support\TenantSettings;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WebhookTenantHostBindingTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<string> */
    private array $extraTenantIds = [];

    /** @var list<int> */
    private array $inboundConnectionIds = [];

    /** @var list<int> */
    private array $outboundConnectionIds = [];

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    #[Test]
    public function wms_webhook_returns_404_when_host_tenant_differs_from_path(): void
    {
        $hostTenant = $this->initializeDemo2Tenant(TenantProfile::DrugWholesaler);
        TenantSettings::forTenant($hostTenant)->setWmsBridgeApiKey('host-wms-key');
        $hostTenant->save();

        $pathTenantId = $this->createExtraTenant();

        tenancy()->end();

        $this->postWebhookOnHost(
            '/api/webhooks/wms/'.$pathTenantId,
            ['scans' => ['(01)30301164005162(21)HOST-MISMATCH']],
            ['HTTP_X_WMS_API_KEY' => 'host-wms-key'],
        )->assertNotFound();
    }

    #[Test]
    public function vrs_webhook_returns_404_when_host_tenant_differs_from_path(): void
    {
        $hostTenant = $this->initializeDemo2Tenant();
        TenantSettings::forTenant($hostTenant)->setVrsResponderApiKey('host-vrs-key');
        $hostTenant->save();

        $pathTenantId = $this->createExtraTenant();

        tenancy()->end();

        $this->postWebhookOnHost(
            '/api/webhooks/vrs/'.$pathTenantId,
            [
                'gtin14' => '00301164005162',
                'serial' => 'HOST-MISMATCH',
            ],
            ['HTTP_X_VRS_API_KEY' => 'host-vrs-key'],
        )->assertNotFound();
    }

    #[Test]
    public function epcis_inbound_webhook_returns_404_when_host_tenant_differs_from_path(): void
    {
        $hostTenant = $this->initializeDemo2Tenant();

        $connection = InboundConnection::query()->create([
            'name' => 'Host Binding EPCIS Test',
            'serialization_provider' => SerializationProvider::CustomHttps,
            'transport' => InboundTransport::Https,
            'is_active' => true,
        ]);
        $this->inboundConnectionIds[] = (int) $connection->id;

        $pathTenantId = $this->createExtraTenant();

        tenancy()->end();

        $this->call(
            'POST',
            'http://'.self::DEMO2_DOMAIN.'/api/webhooks/epcis/'.$pathTenantId.'/'.$connection->id,
            [],
            [],
            [],
            [
                'HTTP_HOST' => self::DEMO2_DOMAIN,
                'HTTP_X_INBOUND_TOKEN' => (string) $connection->inbound_token,
                'CONTENT_TYPE' => 'application/xml',
                'HTTP_ACCEPT' => 'application/json',
            ],
            '<epcis:EPCISDocument xmlns:epcis="urn:epcglobal:epcis:xsd:1"/>',
        )->assertNotFound();
    }

    #[Test]
    public function as2_inbound_webhook_returns_404_when_host_tenant_differs_from_path(): void
    {
        $this->initializeDemo2Tenant();

        $connection = InboundConnection::query()->create([
            'name' => 'Host Binding AS2 Test',
            'serialization_provider' => SerializationProvider::CustomHttps,
            'transport' => InboundTransport::As2,
            'is_active' => true,
            'settings' => [
                'as2_from' => 'PARTNER',
                'as2_to' => 'TRACEPHARMA',
            ],
        ]);
        $this->inboundConnectionIds[] = (int) $connection->id;

        $pathTenantId = $this->createExtraTenant();

        tenancy()->end();

        $this->call(
            'POST',
            'http://'.self::DEMO2_DOMAIN.'/api/webhooks/as2/'.$pathTenantId.'/'.$connection->id,
            [],
            [],
            [],
            [
                'HTTP_HOST' => self::DEMO2_DOMAIN,
                'HTTP_AS2_FROM' => 'PARTNER',
                'HTTP_AS2_TO' => 'TRACEPHARMA',
                'CONTENT_TYPE' => 'application/pkcs7-mime',
                'HTTP_ACCEPT' => 'application/json',
            ],
            'as2-body',
        )->assertNotFound();
    }

    #[Test]
    public function as2_mdn_webhook_returns_404_when_host_tenant_differs_from_path(): void
    {
        $this->initializeDemo2Tenant();

        $connection = OutboundConnection::query()->create([
            'name' => 'Host Binding AS2 MDN Test',
            'serialization_provider' => SerializationProvider::CustomHttps,
            'transport' => OutboundTransport::As2,
            'is_active' => true,
        ]);
        $this->outboundConnectionIds[] = (int) $connection->id;

        $pathTenantId = $this->createExtraTenant();

        tenancy()->end();

        $mdnBody = "Reporting-UA: partner-as2.example\r\nOriginal-Message-ID: <test@tracepharma>\r\nDisposition: automatic-action/MDN-sent-automatically; processed";

        $this->call(
            'POST',
            'http://'.self::DEMO2_DOMAIN.'/api/webhooks/as2/mdn/'.$pathTenantId.'/'.$connection->id,
            content: $mdnBody,
            server: [
                'HTTP_HOST' => self::DEMO2_DOMAIN,
                'CONTENT_TYPE' => 'multipart/report; report-type=disposition-notification',
                'HTTP_ACCEPT' => 'application/json',
            ],
        )->assertNotFound();
    }

    #[Test]
    public function wms_webhook_allows_matching_host_and_path_tenant(): void
    {
        $tenant = $this->initializeDemo2Tenant(TenantProfile::DrugWholesaler);
        TenantSettings::forTenant($tenant)->setWmsBridgeApiKey('match-wms-key');
        $tenant->save();

        tenancy()->end();

        $this->postWebhookOnHost(
            '/api/webhooks/wms/'.self::DEMO2_TENANT_ID,
            ['scans' => ['(01)30301164005162(21)HOST-MATCH']],
            ['HTTP_X_WMS_API_KEY' => 'match-wms-key'],
        )->assertStatus(422);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    private function postWebhookOnHost(string $path, array $data, array $headers = []): TestResponse
    {
        $server = array_merge([
            'HTTP_HOST' => self::DEMO2_DOMAIN,
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ], $headers);

        return $this->call(
            'POST',
            'http://'.self::DEMO2_DOMAIN.$path,
            [],
            [],
            [],
            $server,
            json_encode($data, JSON_THROW_ON_ERROR),
        );
    }

    private function createExtraTenant(): string
    {
        $tenantId = (string) Str::uuid();
        Tenant::withoutEvents(fn () => Tenant::query()->create([
            'id' => $tenantId,
            'name' => 'Webhook Host Binding Path Tenant',
            'profile' => TenantProfile::Pharmacy,
            'status' => 'active',
            'tenancy_db_name' => 'tenant_webhook_host_bind_'.str_replace('-', '', $tenantId),
        ]));
        $this->extraTenantIds[] = $tenantId;

        return $tenantId;
    }

    private function initializeDemo2Tenant(?TenantProfile $profile = null): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo Tenant',
                'profile' => $profile ?? TenantProfile::Pharmacy,
                'status' => 'active',
                'tenancy_db_name' => self::DEMO2_DATABASE,
            ]));

            $tenant->domains()->create(['domain' => self::DEMO2_DOMAIN]);
        } else {
            $tenant->domains()->firstOrCreate(['domain' => self::DEMO2_DOMAIN]);
        }

        if ($profile !== null) {
            $tenant->forceFill(['profile' => $profile])->save();
        }

        if (! self::$demo2TenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();

            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant->fresh());

        return $tenant;
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);
            if ($tenant !== null) {
                tenancy()->initialize($tenant);
            }
        }

        if (tenancy()->initialized) {
            foreach ($this->inboundConnectionIds as $id) {
                InboundConnection::query()->whereKey($id)->delete();
            }
            foreach ($this->outboundConnectionIds as $id) {
                OutboundConnection::query()->whereKey($id)->delete();
            }
        }

        $this->inboundConnectionIds = [];
        $this->outboundConnectionIds = [];

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        foreach ($this->extraTenantIds as $tenantId) {
            Tenant::withoutEvents(fn () => Tenant::query()->whereKey($tenantId)->delete());
        }
        $this->extraTenantIds = [];
    }
}
