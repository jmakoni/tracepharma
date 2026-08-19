<?php

declare(strict_types=1);

namespace Tests\Feature\Tenants;

use App\Enums\AdminRole;
use App\Enums\EpcisAuthoredKind;
use App\Enums\EpcisJobKind;
use App\Enums\EpcisJobStatus;
use App\Enums\InboundTransport;
use App\Enums\OutboundTransport;
use App\Enums\SerializationProvider;
use App\Enums\TenantProfile;
use App\Filament\Admin\Resources\Tenants\Pages\EditTenant;
use App\Jobs\EpcisJobs\TransmitEpcisJob;
use App\Actions\Tenants\ProvisionTenantOnEnvironment;
use App\Actions\Tenants\ProvisionTenantPair;
use App\Models\Admin;
use App\Models\Epcis\EpcisDocument;
use App\Models\EpcisJob;
use App\Models\InboundConnection;
use App\Models\OutboundConnection;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Epcis\Contracts\OutboundEpcisTransmitter;
use App\Support\Auth\AdminRoleSeeder;
use App\Support\EpcisJobs\EpcisJobLogger;
use App\Support\EpcisJobs\EpcisJobStats;
use App\Support\SanctumAbilities;
use App\Support\Tenancy\TenantKillSwitches;
use App\Support\TenantHostname;
use App\Support\TenantSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Stancl\Tenancy\Database\Models\Domain;
use Tests\Concerns\CleansDemo2EpcisArtifacts;
use Tests\TestCase;

class TenantKillSwitchesTest extends TestCase
{
    use CleansDemo2EpcisArtifacts;
    use DatabaseTransactions;

    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $adminIds = [];

    /** @var list<int> */
    private array $jobIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $sessionIds = [];

    /** @var list<string> */
    private array $slugs = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'tracepharma.epcis_jobs.enabled' => true,
            'tracepharma.epcis_jobs.queue' => 'epcis',
        ]);

        app(AdminRoleSeeder::class)->seed();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            if ($this->jobIds !== []) {
                EpcisJob::query()->whereIn('id', $this->jobIds)->each(function (EpcisJob $job): void {
                    $job->messages()->delete();
                    $job->delete();
                });
            }
            if ($this->sessionIds !== []) {
                OutboundShippingSession::query()->whereIn('id', $this->sessionIds)->delete();
            }
            if ($this->documentIds !== []) {
                EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            }
            tenancy()->end();
        }

        $this->clearKillSwitchesOnDemo2();

        foreach ($this->slugs as $slug) {
            $this->destroyPair($slug);
        }

        if ($this->adminIds !== []) {
            DB::table('model_has_roles')
                ->where('model_type', Admin::class)
                ->whereIn('model_id', $this->adminIds)
                ->delete();
            DB::table('admins')->whereIn('id', $this->adminIds)->delete();
        }

        parent::tearDown();
    }

    #[Test]
    public function kill_switches_default_to_allowed(): void
    {
        $tenant = $this->ensureDemo2Tenant();

        $switches = TenantKillSwitches::forTenant($tenant);

        $this->assertFalse($switches->outboundEpcisKilled());
        $this->assertFalse($switches->inboundEpcisKilled());
        $this->assertFalse($switches->sanctumApiKilled());
        $this->assertFalse($switches->wmsWebhooksKilled());
    }

    #[Test]
    public function outbound_epcis_kill_switch_blocks_transmit_job(): void
    {
        $tenant = $this->ensureDemo2Tenant();
        $this->setKillSwitch($tenant, TenantKillSwitches::OUTBOUND_EPCIS, true);

        tenancy()->initialize($tenant);
        [$document] = $this->seedShippingDocument();

        try {
            $job = EpcisJob::query()->create([
                'receipt' => str_replace('-', '', (string) Str::uuid()),
                'kind' => EpcisJobKind::OutboundShipping,
                'status' => EpcisJobStatus::Queued,
                'epcis_document_id' => $document->getKey(),
                'outbound_shipping_session_id' => $this->sessionIds[0] ?? null,
                'original_filename' => $document->original_filename,
                'received_at' => now(),
                'attempt_count' => 0,
            ]);
            $this->jobIds[] = (int) $job->getKey();

            $document->forceFill(['transmission_status' => 'queued'])->save();

            $queueJob = new TransmitEpcisJob($tenant, (int) $job->getKey());
            $queueJob->handle(
                app(OutboundEpcisTransmitter::class),
                app(EpcisJobLogger::class),
                app(EpcisJobStats::class),
            );

            $job->refresh();
            $this->assertSame(EpcisJobStatus::Error, $job->status);
            $this->assertSame(
                TenantKillSwitches::blockedMessage(TenantKillSwitches::OUTBOUND_EPCIS),
                $job->error_message,
            );
            $this->assertSame('failed', $document->fresh()->transmission_status);
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function inbound_epcis_kill_switch_blocks_sanctum_api_upload(): void
    {
        $tenant = $this->ensureDemo2Tenant();
        $this->setKillSwitch($tenant, TenantKillSwitches::INBOUND_EPCIS, true);

        tenancy()->initialize($tenant);

        try {
            $user = User::factory()->create();
            $token = $user->createToken('kill-switch-test', [SanctumAbilities::EPCIS_UPLOAD])->plainTextToken;

            $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
            $xml = file_get_contents($fixture);
            $this->assertNotFalse($xml);
            $xml = str_replace('11111111-2222-3333-4444-555555555555', (string) str()->uuid(), $xml);

            tenancy()->end();

            $this->call(
                'POST',
                'http://'.self::DEMO2_DOMAIN.'/api/v1/epcis/inbound',
                [],
                [],
                [],
                [
                    'HTTP_HOST' => self::DEMO2_DOMAIN,
                    'HTTP_ACCEPT' => 'application/json',
                    'HTTP_AUTHORIZATION' => 'Bearer '.$token,
                    'HTTP_X-Original-Filename' => 'kill-switch-api-test.xml',
                    'CONTENT_TYPE' => 'application/xml',
                ],
                $xml,
            )->assertForbidden()
                ->assertJson([
                    'message' => TenantKillSwitches::blockedMessage(TenantKillSwitches::INBOUND_EPCIS),
                ]);
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function outbound_epcis_kill_switch_blocks_sanctum_api_upload(): void
    {
        $tenant = $this->ensureDemo2Tenant();
        $this->setKillSwitch($tenant, TenantKillSwitches::OUTBOUND_EPCIS, true);

        tenancy()->initialize($tenant);

        try {
            $connection = OutboundConnection::query()->create([
                'name' => 'Kill Switch Outbound API Test',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::Https,
                'is_active' => true,
                'settings' => ['endpoint_url' => 'https://kill-switch.example/epcis'],
            ]);
            $this->trackOutboundConnectionId((int) $connection->id);

            $user = User::factory()->create();
            $token = $user->createToken('kill-switch-test', [SanctumAbilities::EPCIS_TRANSMIT])->plainTextToken;

            $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
            $xml = file_get_contents($fixture);
            $this->assertNotFalse($xml);
            $xml = str_replace('11111111-2222-3333-4444-555555555555', (string) str()->uuid(), $xml);

            tenancy()->end();

            $this->call(
                'POST',
                'http://'.self::DEMO2_DOMAIN.'/api/v1/epcis/outbound?outbound_connection_id='.$connection->id,
                [],
                [],
                [],
                [
                    'HTTP_HOST' => self::DEMO2_DOMAIN,
                    'HTTP_ACCEPT' => 'application/json',
                    'HTTP_AUTHORIZATION' => 'Bearer '.$token,
                    'HTTP_X-Original-Filename' => 'kill-switch-outbound-api-test.xml',
                    'CONTENT_TYPE' => 'application/xml',
                ],
                $xml,
            )->assertForbidden()
                ->assertJson([
                    'message' => TenantKillSwitches::blockedMessage(TenantKillSwitches::OUTBOUND_EPCIS),
                ]);
        } finally {
            $this->cleanupTrackedEpcisArtifacts();
            tenancy()->end();
        }
    }

    #[Test]
    public function inbound_epcis_kill_switch_blocks_documents_list(): void
    {
        $tenant = $this->ensureDemo2Tenant();
        $this->setKillSwitch($tenant, TenantKillSwitches::INBOUND_EPCIS, true);

        tenancy()->initialize($tenant);

        try {
            $user = User::factory()->create();
            $token = $user->createToken('kill-switch-test', [SanctumAbilities::EPCIS_VIEW])->plainTextToken;

            tenancy()->end();

            $this->call(
                'GET',
                'http://'.self::DEMO2_DOMAIN.'/api/v1/epcis/documents',
                [],
                [],
                [],
                [
                    'HTTP_HOST' => self::DEMO2_DOMAIN,
                    'HTTP_ACCEPT' => 'application/json',
                    'HTTP_AUTHORIZATION' => 'Bearer '.$token,
                ],
            )->assertForbidden()
                ->assertJson([
                    'message' => TenantKillSwitches::blockedMessage(TenantKillSwitches::INBOUND_EPCIS),
                ]);
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function inbound_epcis_kill_switch_blocks_webhook_processing(): void
    {
        $tenant = $this->ensureDemo2Tenant();
        $this->setKillSwitch($tenant, TenantKillSwitches::INBOUND_EPCIS, true);

        tenancy()->initialize($tenant);

        try {
            $connection = InboundConnection::query()->create([
                'name' => 'Kill Switch Webhook Test',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => InboundTransport::Https,
                'is_active' => true,
            ]);
            $this->trackInboundConnectionId((int) $connection->id);

            $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
            $xml = file_get_contents($fixture);
            $this->assertNotFalse($xml);
            $xml = str_replace('11111111-2222-3333-4444-555555555555', (string) str()->uuid(), $xml);

            tenancy()->end();

            $this->call(
                'POST',
                '/api/webhooks/epcis/'.$tenant->id.'/'.$connection->id,
                [],
                [],
                [],
                [
                    'HTTP_X_INBOUND_TOKEN' => $connection->inbound_token,
                    'HTTP_X_ORIGINAL_FILENAME' => 'kill-switch-test.xml',
                    'CONTENT_TYPE' => 'application/xml',
                    'HTTP_ACCEPT' => 'application/json',
                ],
                $xml,
            )->assertForbidden()
                ->assertJson([
                    'message' => TenantKillSwitches::blockedMessage(TenantKillSwitches::INBOUND_EPCIS),
                ]);
        } finally {
            $this->cleanupTrackedEpcisArtifacts();
            tenancy()->end();
        }
    }

    #[Test]
    public function outbound_epcis_kill_switch_blocks_as2_mdn_webhook(): void
    {
        $tenant = $this->ensureDemo2Tenant();
        $this->setKillSwitch($tenant, TenantKillSwitches::OUTBOUND_EPCIS, true);

        tenancy()->initialize($tenant);

        try {
            $connection = OutboundConnection::query()->create([
                'name' => 'Kill Switch AS2 MDN Test',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::As2,
                'is_active' => true,
            ]);
            $this->trackOutboundConnectionId((int) $connection->id);

            tenancy()->end();

            $mdnBody = "Reporting-UA: partner-as2.example\r\nOriginal-Message-ID: <test@tracepharma>\r\nDisposition: automatic-action/MDN-sent-automatically; processed";

            $this->call(
                'POST',
                '/api/webhooks/as2/mdn/'.$tenant->id.'/'.$connection->id,
                content: $mdnBody,
                server: [
                    'CONTENT_TYPE' => 'multipart/report; report-type=disposition-notification',
                    'HTTP_ACCEPT' => 'application/json',
                ],
            )->assertForbidden()
                ->assertJson([
                    'message' => TenantKillSwitches::blockedMessage(TenantKillSwitches::OUTBOUND_EPCIS),
                ]);
        } finally {
            $this->cleanupTrackedEpcisArtifacts();
            tenancy()->end();
        }
    }

    #[Test]
    public function inbound_epcis_kill_switch_does_not_block_as2_mdn_webhook(): void
    {
        $tenant = $this->ensureDemo2Tenant();
        $this->setKillSwitch($tenant, TenantKillSwitches::INBOUND_EPCIS, true);
        $this->setKillSwitch($tenant, TenantKillSwitches::OUTBOUND_EPCIS, false);

        tenancy()->initialize($tenant);

        try {
            $connection = OutboundConnection::query()->create([
                'name' => 'Inbound Kill AS2 MDN Test',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::As2,
                'is_active' => true,
            ]);
            $this->trackOutboundConnectionId((int) $connection->id);

            tenancy()->end();

            $mdnBody = "Reporting-UA: partner-as2.example\r\nOriginal-Message-ID: <test@tracepharma>\r\nDisposition: automatic-action/MDN-sent-automatically; processed";

            $this->call(
                'POST',
                '/api/webhooks/as2/mdn/'.$tenant->id.'/'.$connection->id,
                content: $mdnBody,
                server: [
                    'CONTENT_TYPE' => 'multipart/report; report-type=disposition-notification',
                    'HTTP_ACCEPT' => 'application/json',
                ],
            )->assertUnauthorized();
        } finally {
            $this->cleanupTrackedEpcisArtifacts();
            tenancy()->end();
        }
    }

    #[Test]
    public function support_admin_cannot_edit_tenant_kill_switches(): void
    {
        $tenant = $this->ensureDemo2Tenant();

        $this->actAsAdmin(AdminRole::Support);

        Livewire::test(EditTenant::class, ['record' => $tenant->getKey()])
            ->assertForbidden();
    }

    #[Test]
    public function platform_admin_can_persist_kill_switches_on_edit_tenant(): void
    {
        $tenant = $this->ensureDemo2Tenant();

        $this->actAsAdmin(AdminRole::PlatformAdmin);

        Livewire::test(EditTenant::class, ['record' => $tenant->getKey()])
            ->fillForm([
                'kill_switch_outbound_epcis' => true,
                'kill_switch_inbound_epcis' => false,
                'kill_switch_sanctum_api' => true,
                'kill_switch_wms_webhooks' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = TenantSettings::forTenant($tenant->fresh());

        $this->assertTrue($fresh->outboundEpcisKilled());
        $this->assertFalse($fresh->inboundEpcisKilled());
        $this->assertTrue($fresh->sanctumApiKilled());
        $this->assertFalse($fresh->wmsWebhooksKilled());

        $this->clearKillSwitchesOnDemo2();
    }

    #[Test]
    public function prod_kill_switches_cascade_to_stage_sibling(): void
    {
        $slug = 'kill-cascade-'.Str::lower(Str::random(6));
        $this->slugs[] = $slug;

        $prod = app(ProvisionTenantPair::class)->create($slug, [
            'name' => 'Kill Cascade '.$slug,
            'profile' => TenantProfile::DrugWholesaler,
            'status' => 'active',
        ]);

        $stage = app(ProvisionTenantOnEnvironment::class)->findBySlugAndEnvironment($slug, 'stage');
        $this->assertNotNull($stage);

        $this->actAsAdmin(AdminRole::PlatformAdmin);

        Livewire::test(EditTenant::class, ['record' => $prod->getKey()])
            ->fillForm([
                'kill_switch_outbound_epcis' => true,
                'kill_switch_inbound_epcis' => true,
                'kill_switch_sanctum_api' => false,
                'kill_switch_wms_webhooks' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $prodSettings = TenantSettings::forTenant($prod->fresh());
        $stageSettings = TenantSettings::forTenant($stage->fresh());

        $this->assertTrue($prodSettings->outboundEpcisKilled());
        $this->assertTrue($prodSettings->inboundEpcisKilled());
        $this->assertFalse($prodSettings->sanctumApiKilled());
        $this->assertTrue($prodSettings->wmsWebhooksKilled());

        $this->assertTrue($stageSettings->outboundEpcisKilled());
        $this->assertTrue($stageSettings->inboundEpcisKilled());
        $this->assertFalse($stageSettings->sanctumApiKilled());
        $this->assertTrue($stageSettings->wmsWebhooksKilled());
    }

    private function ensureDemo2Tenant(): Tenant
    {
        if (! self::$demo2TenantReady) {
            $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);
            $this->assertNotNull($tenant);

            if ($tenant->profile !== TenantProfile::DrugWholesaler) {
                $tenant->forceFill(['profile' => TenantProfile::DrugWholesaler, 'status' => 'active'])->save();
            }

            self::$demo2TenantReady = true;
        }

        $tenant = Tenant::query()->findOrFail(self::DEMO2_TENANT_ID);
        $tenant->update(['status' => 'active']);

        return $tenant->fresh();
    }

    private function setKillSwitch(Tenant $tenant, string $key, bool $killed): void
    {
        TenantSettings::forTenant($tenant)->setKillSwitch($key, $killed);
        $tenant->save();
    }

    private function clearKillSwitchesOnDemo2(): void
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            return;
        }

        $settings = TenantSettings::forTenant($tenant);

        foreach (TenantKillSwitches::KEYS as $key) {
            $settings->setKillSwitch($key, false);
        }

        $tenant->save();
    }

    /**
     * @return array{0: EpcisDocument}
     */
    private function seedShippingDocument(): array
    {
        Storage::fake('local');

        $site = Site::query()->whereNotNull('gln')->where('is_organization_facility', true)->first()
            ?? Site::query()->whereNotNull('gln')->first();
        $this->assertNotNull($site);

        $path = 'epcis/outbound/kill-switch-'.Str::lower(Str::random(8)).'.xml';
        Storage::disk('local')->put($path, '<?xml version="1.0"?><epcis:EPCISDocument xmlns:epcis="urn:epcglobal:epcis:xsd:1" creationDate="2026-08-09T00:00:00.000Z"></epcis:EPCISDocument>');

        $document = EpcisDocument::query()->create([
            'document_uuid' => 'urn:uuid:'.Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'direction' => 'outbound',
            'authored_kind' => EpcisAuthoredKind::Shipping,
            'format' => 'xml',
            'original_filename' => basename($path),
            'payload_disk' => 'local',
            'payload_path' => $path,
            'file_sha256' => hash('sha256', 'x'),
            'status' => 'generated',
            'received_at' => now(),
            'ship_from_site_id' => $site->getKey(),
            'event_count' => 0,
            'epc_count' => 0,
            'reprocess_count' => 0,
            'notes' => 'Generated outbound shipping EPCIS for kill switch test.',
        ]);
        $this->documentIds[] = (int) $document->getKey();

        $session = OutboundShippingSession::query()->create([
            'site_id' => $site->getKey(),
            'status' => 'completed',
            'dscsa_affirm' => true,
            'expected_count' => 0,
            'confirmed_count' => 0,
            'epcis_document_id' => $document->getKey(),
            'shipping_events_generated_at' => now(),
            'opened_at' => now(),
            'completed_at' => now(),
            'asn_number' => 'TEST-ASN',
        ]);
        $this->sessionIds[] = (int) $session->getKey();

        return [$document];
    }

    private function actAsAdmin(AdminRole $role): Admin
    {
        $admin = Admin::factory()->create();
        $admin->assignRole($role->value);
        $this->adminIds[] = (int) $admin->getKey();

        $this->actingAs($admin, 'admin');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $admin;
    }

    private function destroyPair(string $slug): void
    {
        foreach (TenantHostname::PAIR_ENVIRONMENTS as $environment) {
            $domain = Domain::query()
                ->where('domain', TenantHostname::forSlug($slug, $environment))
                ->first();

            if ($domain === null) {
                continue;
            }

            Tenant::query()->find($domain->tenant_id)?->delete();
        }
    }
}
