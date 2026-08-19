<?php

namespace Tests\Feature\Epcis;

use App\Enums\EpcisJobKind;
use App\Enums\OutboundTransport;
use App\Enums\PartnerType;
use App\Enums\SerializationProvider;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Jobs\EpcisJobs\TransmitEpcisJob;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\TransmissionMdn;
use App\Models\EpcisJob;
use App\Models\OutboundConnection;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\SanctumAbilities;
use App\Support\TenantSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EpcisOutboundApiTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $partnerIds = [];

    /** @var list<int> */
    private array $connectionIds = [];

    /** @var list<int> */
    private array $jobIds = [];

    /** @var list<int> */
    private array $mdnIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $userIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'tracepharma.epcis_jobs.enabled' => true,
            'tracepharma.epcis_jobs.queue' => 'epcis',
        ]);
    }

    #[Test]
    public function outbound_accepts_raw_xml_and_queues_transmission(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Bus::fake([TransmitEpcisJob::class]);

            $user = User::factory()->create();
            $token = $user->createToken('epcis-transmit', [SanctumAbilities::EPCIS_TRANSMIT])->plainTextToken;
            $connection = $this->createGlobalConnection();
            $xml = $this->uniqueFixtureXml('tests/Fixtures/epcis/minimal_object_shipping.xml');

            tenancy()->end();

            $response = $this->tenantApiPost(
                '/api/v1/epcis/outbound?outbound_connection_id='.$connection->getKey(),
                $token,
                $xml,
                [
                'CONTENT_TYPE' => 'application/xml',
                'HTTP_X-Original-Filename' => 'minimal_object_shipping.xml',
            ]);

            $response->assertAccepted()
                ->assertJson([
                    'message' => 'EPCIS document accepted for outbound transmission.',
                    'status' => 'received',
                    'transmission_status' => 'queued',
                ])
                ->assertJsonStructure([
                    'message',
                    'document_id',
                    'document_uuid',
                    'status',
                    'transmission_status',
                ]);

            tenancy()->initialize(Tenant::query()->find(self::DEMO2_TENANT_ID));
            $document = EpcisDocument::query()->find($response->json('document_id'));
            $this->assertNotNull($document);
            $this->documentIds[] = (int) $document->getKey();
            $this->assertSame('outbound', $document->direction);
            $this->assertSame('api', $document->received_via?->value);

            $job = EpcisJob::query()->where('epcis_document_id', $document->getKey())->first();
            $this->assertNotNull($job);
            $this->jobIds[] = (int) $job->getKey();
            $this->assertSame(EpcisJobKind::OutboundApi, $job->kind);

            Bus::assertDispatched(TransmitEpcisJob::class);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function outbound_accepts_multipart_file_upload(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Bus::fake([TransmitEpcisJob::class]);

            $user = User::factory()->create();
            $token = $user->createToken('epcis-transmit', [SanctumAbilities::EPCIS_TRANSMIT])->plainTextToken;
            $connection = $this->createGlobalConnection();
            $xml = $this->uniqueFixtureXml('tests/Fixtures/epcis/minimal_object_shipping.xml');
            $file = UploadedFile::fake()->createWithContent('minimal_object_shipping.xml', $xml);

            tenancy()->end();

            $response = $this->tenantApiMultipartPost(
                '/api/v1/epcis/outbound?outbound_connection_id='.$connection->getKey(),
                $token,
                [
                'file' => $file,
            ]);

            $response->assertAccepted()
                ->assertJsonPath('transmission_status', 'queued');

            tenancy()->initialize(Tenant::query()->find(self::DEMO2_TENANT_ID));
            $this->documentIds[] = (int) $response->json('document_id');
            $this->jobIds = EpcisJob::query()
                ->where('epcis_document_id', $response->json('document_id'))
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function outbound_transmits_via_https_when_jobs_disabled(): void
    {
        config(['tracepharma.epcis_jobs.enabled' => false]);

        $this->initializeDemo2Tenant();

        try {
            Http::fake([
                'https://partner.example/epcis' => Http::response('OK', 202),
            ]);

            $connection = OutboundConnection::query()->create([
                'name' => 'Partner HTTPS API test',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::Https,
                'is_active' => true,
                'is_default' => true,
                'settings' => ['endpoint_url' => 'https://partner.example/epcis'],
                'credentials' => ['webhook_token' => 'outbound-token'],
            ]);
            $this->connectionIds[] = (int) $connection->getKey();

            $user = User::factory()->create();
            $token = $user->createToken('epcis-transmit', [SanctumAbilities::EPCIS_TRANSMIT])->plainTextToken;
            $xml = $this->uniqueFixtureXml('tests/Fixtures/epcis/minimal_object_shipping.xml');

            tenancy()->end();

            $response = $this->tenantApiPost(
                '/api/v1/epcis/outbound?outbound_connection_id='.$connection->getKey(),
                $token,
                $xml,
                [
                    'CONTENT_TYPE' => 'application/xml',
                ],
            );

            $response->assertAccepted()
                ->assertJsonPath('transmission_status', 'sent');

            tenancy()->initialize(Tenant::query()->find(self::DEMO2_TENANT_ID));
            $this->documentIds[] = (int) $response->json('document_id');

            Http::assertSent(fn ($request): bool => $request->url() === 'https://partner.example/epcis');
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function outbound_returns_duplicate_response_for_same_payload(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Bus::fake([TransmitEpcisJob::class]);

            $user = User::factory()->create();
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);
            $user->assignRole(TenantRole::Owner->value);
            $token = $user->createToken('epcis-transmit', [SanctumAbilities::EPCIS_TRANSMIT])->plainTextToken;
            $connection = $this->createGlobalConnection();
            $xml = $this->uniqueFixtureXml('tests/Fixtures/epcis/minimal_object_shipping.xml');

            tenancy()->end();

            $first = $this->tenantApiPost(
                '/api/v1/epcis/outbound?outbound_connection_id='.$connection->getKey(),
                $token,
                $xml,
                [
                'CONTENT_TYPE' => 'application/xml',
            ]);
            $first->assertAccepted();

            $duplicate = $this->tenantApiPost(
                '/api/v1/epcis/outbound?outbound_connection_id='.$connection->getKey(),
                $token,
                $xml,
                [
                'CONTENT_TYPE' => 'application/xml',
            ]);

            $duplicate->assertStatus(409)
                ->assertJson([
                    'message' => 'EPCIS document already received.',
                    'document_id' => $first->json('document_id'),
                    'document_uuid' => $first->json('document_uuid'),
                    'duplicate' => true,
                ]);

            tenancy()->initialize(Tenant::query()->find(self::DEMO2_TENANT_ID));
            $this->documentIds[] = (int) $first->json('document_id');
            $this->jobIds = EpcisJob::query()
                ->where('epcis_document_id', $first->json('document_id'))
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function outbound_rejects_mismatched_trading_partner_and_connection(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Bus::fake([TransmitEpcisJob::class]);

            $partnerA = TradingPartner::query()->create([
                'name' => 'API partner A '.uniqid(),
                'gln' => '0366159000010',
                'partner_type' => PartnerType::Pharmacy,
                'country_code' => 'US',
                'is_active' => true,
            ]);
            $this->partnerIds[] = (int) $partnerA->getKey();

            $partnerB = TradingPartner::query()->create([
                'name' => 'API partner B '.uniqid(),
                'gln' => '0366159000027',
                'partner_type' => PartnerType::Pharmacy,
                'country_code' => 'US',
                'is_active' => true,
            ]);
            $this->partnerIds[] = (int) $partnerB->getKey();

            $connectionForB = OutboundConnection::query()->create([
                'name' => 'Partner B HTTPS',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::Https,
                'trading_partner_id' => $partnerB->getKey(),
                'is_active' => true,
                'settings' => ['endpoint_url' => 'https://partner-b.example/epcis'],
            ]);
            $this->connectionIds[] = (int) $connectionForB->getKey();

            $user = User::factory()->create();
            $token = $user->createToken('epcis-transmit', [SanctumAbilities::EPCIS_TRANSMIT])->plainTextToken;
            $xml = $this->uniqueFixtureXml('tests/Fixtures/epcis/minimal_object_shipping.xml');

            tenancy()->end();

            $this->tenantApiPost(
                '/api/v1/epcis/outbound?trading_partner_id='.$partnerA->getKey().'&outbound_connection_id='.$connectionForB->getKey(),
                $token,
                $xml,
                ['CONTENT_TYPE' => 'application/xml'],
            )->assertUnprocessable()
                ->assertJsonValidationErrors(['outbound_connection_id']);

            Bus::assertNothingDispatched();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function outbound_requires_trading_partner_or_connection(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Bus::fake([TransmitEpcisJob::class]);

            $user = User::factory()->create();
            $token = $user->createToken('epcis-transmit', [SanctumAbilities::EPCIS_TRANSMIT])->plainTextToken;
            $xml = $this->uniqueFixtureXml('tests/Fixtures/epcis/minimal_object_shipping.xml');

            tenancy()->end();

            $this->tenantApiPost('/api/v1/epcis/outbound', $token, $xml, [
                'CONTENT_TYPE' => 'application/xml',
            ])->assertUnprocessable()
                ->assertJsonValidationErrors(['trading_partner_id']);

            Bus::assertNothingDispatched();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function outbound_requires_epcis_transmit_ability(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $user = User::factory()->create();
            $token = $user->createToken('epcis-test', ['view'])->plainTextToken;

            tenancy()->end();

            $this->tenantApiPost('/api/v1/epcis/outbound', $token, '<epcis/>', [
                'CONTENT_TYPE' => 'application/xml',
            ])->assertForbidden();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function outbound_requires_authentication(): void
    {
        $this->initializeDemo2Tenant();

        try {
            tenancy()->end();

            $this->tenantApiPost('/api/v1/epcis/outbound', null, '<epcis/>', [
                'CONTENT_TYPE' => 'application/xml',
            ])->assertUnauthorized();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function outbound_requires_nav_ship_when_job_roles_are_enabled(): void
    {
        $tenant = $this->initializeDemo2Tenant(TenantProfile::Logistics3pl);

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Logistics3pl);
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
            TenantSettings::forTenant($tenant)->setJobRolesEnabled(true);
            $tenant->save();

            $connection = $this->createGlobalConnection();

            $user = User::factory()->create();
            $user->syncRoles([TenantRole::WmsIntegrationSpecialist->value]);
            $token = $user->createToken('epcis-transmit', [SanctumAbilities::EPCIS_TRANSMIT])->plainTextToken;
            $xml = $this->uniqueFixtureXml('tests/Fixtures/epcis/minimal_object_shipping.xml');

            tenancy()->end();

            $this->tenantApiPost(
                '/api/v1/epcis/outbound?outbound_connection_id='.$connection->getKey(),
                $token,
                $xml,
                ['CONTENT_TYPE' => 'application/xml'],
            )->assertForbidden();
        } finally {
            TenantSettings::forTenant($tenant)->setJobRolesEnabled(false);
            $tenant->save();
            $this->cleanup();
        }
    }

    #[Test]
    public function outbound_is_forbidden_for_inbound_only_tenant_profiles(): void
    {
        $tenant = $this->initializeDemo2Tenant(TenantProfile::Pharmacy);

        try {
            $connection = $this->createGlobalConnection();
            $user = User::factory()->create();
            $token = $user->createToken('epcis-transmit', [SanctumAbilities::EPCIS_TRANSMIT])->plainTextToken;

            tenancy()->end();

            $this->tenantApiPost(
                '/api/v1/epcis/outbound?outbound_connection_id='.$connection->getKey(),
                $token,
                '<epcis/>',
                [
                'CONTENT_TYPE' => 'application/xml',
            ])->assertForbidden();
        } finally {
            $tenant->forceFill(['profile' => TenantProfile::DrugWholesaler])->save();
            $this->cleanup();
        }
    }

    #[Test]
    public function show_returns_status_and_mdn_summary(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Bus::fake([TransmitEpcisJob::class]);

            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $token = $user->createToken('epcis-transmit', [
                SanctumAbilities::EPCIS_TRANSMIT,
                SanctumAbilities::EPCIS_VIEW,
            ])->plainTextToken;
            $connection = $this->createGlobalConnection();
            $xml = $this->uniqueFixtureXml('tests/Fixtures/epcis/minimal_object_shipping.xml');

            tenancy()->end();

            $upload = $this->tenantApiPost(
                '/api/v1/epcis/outbound?outbound_connection_id='.$connection->getKey(),
                $token,
                $xml,
                [
                'CONTENT_TYPE' => 'application/xml',
            ]);
            $upload->assertAccepted();

            tenancy()->initialize(Tenant::query()->find(self::DEMO2_TENANT_ID));
            $documentId = (int) $upload->json('document_id');
            $this->documentIds[] = $documentId;
            $document = EpcisDocument::query()->findOrFail($documentId);
            $document->forceFill(['transmission_status' => 'sent', 'sent_at' => now()])->save();

            $mdnBody = "Reporting-UA: partner.example\r\nDisposition: automatic-action/MDN-sent-automatically; processed";
            $mdn = TransmissionMdn::query()->create([
                'document_id' => $documentId,
                'mdn_status' => 'received',
                'mdn_received_at' => now(),
                'mdn_payload' => [
                    'body' => $mdnBody,
                    'http_status' => 200,
                ],
            ]);
            $this->mdnIds[] = (int) $mdn->getKey();

            tenancy()->end();

            $response = $this->tenantApiGet(
                '/api/v1/epcis/outbound/'.$upload->json('document_uuid'),
                $token,
            );

            $response->assertOk()
                ->assertJsonPath('document_uuid', $upload->json('document_uuid'))
                ->assertJsonPath('transmission_status', 'sent')
                ->assertJsonPath('mdn.status', 'received')
                ->assertJsonPath('mdn.disposition', 'automatic-action/MDN-sent-automatically; processed');
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function show_accepts_epcis_view_ability(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Bus::fake([TransmitEpcisJob::class]);

            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $uploadToken = $user->createToken('upload', [SanctumAbilities::EPCIS_TRANSMIT])->plainTextToken;
            $viewToken = $user->createToken('view', [SanctumAbilities::EPCIS_VIEW])->plainTextToken;
            $connection = $this->createGlobalConnection();
            $xml = $this->uniqueFixtureXml('tests/Fixtures/epcis/minimal_object_shipping.xml');

            tenancy()->end();

            $upload = $this->tenantApiPost(
                '/api/v1/epcis/outbound?outbound_connection_id='.$connection->getKey(),
                $uploadToken,
                $xml,
                [
                'CONTENT_TYPE' => 'application/xml',
            ]);
            $upload->assertAccepted();

            $this->tenantApiGet(
                '/api/v1/epcis/outbound/'.$upload->json('document_uuid'),
                $viewToken,
            )->assertOk();

            tenancy()->initialize(Tenant::query()->find(self::DEMO2_TENANT_ID));
            $this->documentIds[] = (int) $upload->json('document_id');
            $this->jobIds = EpcisJob::query()
                ->where('epcis_document_id', $upload->json('document_id'))
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function show_rejects_numeric_document_id_lookup(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Bus::fake([TransmitEpcisJob::class]);

            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $token = $user->createToken('epcis-transmit', [
                SanctumAbilities::EPCIS_TRANSMIT,
                SanctumAbilities::EPCIS_VIEW,
            ])->plainTextToken;
            $connection = $this->createGlobalConnection();
            $xml = $this->uniqueFixtureXml('tests/Fixtures/epcis/minimal_object_shipping.xml');

            tenancy()->end();

            $upload = $this->tenantApiPost(
                '/api/v1/epcis/outbound?outbound_connection_id='.$connection->getKey(),
                $token,
                $xml,
                [
                    'CONTENT_TYPE' => 'application/xml',
                ],
            );
            $upload->assertAccepted();

            tenancy()->initialize(Tenant::query()->find(self::DEMO2_TENANT_ID));
            $this->documentIds[] = (int) $upload->json('document_id');

            tenancy()->end();

            $this->tenantApiGet(
                '/api/v1/epcis/outbound/'.$upload->json('document_id'),
                $token,
            )->assertNotFound();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function show_respects_ship_from_site_access(): void
    {
        $this->initializeDemo2Tenant();

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);

            $siteA = Site::factory()->owned()->create([
                'name' => 'Outbound API Site A '.Str::random(6),
                'gln' => '0366159000118',
                'is_active' => true,
            ]);
            $siteB = Site::factory()->owned()->create([
                'name' => 'Outbound API Site B '.Str::random(6),
                'gln' => '0366159000125',
                'is_active' => true,
            ]);
            $this->siteIds = [(int) $siteA->getKey(), (int) $siteB->getKey()];

            $user = User::factory()->create();
            $user->syncSites([(int) $siteA->getKey()]);
            $this->userIds[] = (int) $user->getKey();

            $token = $user->createToken('epcis-view', [SanctumAbilities::EPCIS_VIEW])->plainTextToken;

            $accessibleDocument = EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'outbound',
                'format' => 'xml',
                'original_filename' => 'accessible-outbound.xml',
                'payload_disk' => 'local',
                'payload_path' => 'epcis/outbound/accessible-'.Str::uuid().'.xml',
                'file_sha256' => hash('sha256', 'accessible-outbound'),
                'dscsa_affirm' => false,
                'status' => 'received',
                'reprocess_count' => 0,
                'event_count' => 0,
                'epc_count' => 0,
                'received_at' => now(),
                'ship_from_site_id' => $siteA->getKey(),
            ]);
            $this->documentIds[] = (int) $accessibleDocument->getKey();

            $restrictedDocument = EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'outbound',
                'format' => 'xml',
                'original_filename' => 'restricted-outbound.xml',
                'payload_disk' => 'local',
                'payload_path' => 'epcis/outbound/restricted-'.Str::uuid().'.xml',
                'file_sha256' => hash('sha256', 'restricted-outbound'),
                'dscsa_affirm' => false,
                'status' => 'received',
                'reprocess_count' => 0,
                'event_count' => 0,
                'epc_count' => 0,
                'received_at' => now(),
                'ship_from_site_id' => $siteB->getKey(),
            ]);
            $this->documentIds[] = (int) $restrictedDocument->getKey();

            tenancy()->end();

            $this->tenantApiGet(
                '/api/v1/epcis/outbound/'.$accessibleDocument->document_uuid,
                $token,
            )->assertOk()
                ->assertJsonPath('document_uuid', $accessibleDocument->document_uuid);

            $this->tenantApiGet(
                '/api/v1/epcis/outbound/'.$restrictedDocument->document_uuid,
                $token,
            )->assertNotFound();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function show_requires_nav_ship_when_job_roles_are_enabled(): void
    {
        $tenant = $this->initializeDemo2Tenant(TenantProfile::Logistics3pl);

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Logistics3pl);
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
            TenantSettings::forTenant($tenant)->setJobRolesEnabled(true);
            $tenant->save();

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'outbound',
                'format' => 'xml',
                'original_filename' => 'show-nav-ship.xml',
                'payload_disk' => 'local',
                'payload_path' => 'epcis/outbound/show-nav-ship-'.Str::uuid().'.xml',
                'file_sha256' => hash('sha256', 'show-nav-ship'),
                'dscsa_affirm' => false,
                'status' => 'received',
                'reprocess_count' => 0,
                'event_count' => 0,
                'epc_count' => 0,
                'received_at' => now(),
            ]);
            $this->documentIds[] = (int) $document->getKey();

            $user = User::factory()->create();
            $user->syncRoles([TenantRole::WmsIntegrationSpecialist->value]);
            $token = $user->createToken('epcis-view', [SanctumAbilities::EPCIS_VIEW])->plainTextToken;

            tenancy()->end();

            $this->tenantApiGet(
                '/api/v1/epcis/outbound/'.$document->document_uuid,
                $token,
            )->assertForbidden();
        } finally {
            TenantSettings::forTenant($tenant)->setJobRolesEnabled(false);
            $tenant->save();
            $this->cleanup();
        }
    }

    #[Test]
    public function show_requires_view_or_transmit_ability(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $user = User::factory()->create();
            $token = $user->createToken('epcis-test', ['view'])->plainTextToken;

            tenancy()->end();

            $this->tenantApiGet('/api/v1/epcis/outbound/00000000-0000-0000-0000-000000000001', $token)
                ->assertForbidden();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function outbound_store_rejects_ship_from_site_outside_user_scope(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Bus::fake([TransmitEpcisJob::class]);

            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);

            $siteAGln = $this->uniqueGln();
            $siteBGln = $this->uniqueGln();

            $siteA = Site::query()->create([
                'name' => 'Outbound API Site A '.Str::random(6),
                'gln' => $siteAGln,
                'is_active' => true,
                'is_organization_facility' => true,
                'trading_partner_id' => null,
            ]);
            $siteB = Site::query()->create([
                'name' => 'Outbound API Site B '.Str::random(6),
                'gln' => $siteBGln,
                'is_active' => true,
                'is_organization_facility' => true,
                'trading_partner_id' => null,
            ]);
            $this->siteIds = [(int) $siteA->getKey(), (int) $siteB->getKey()];

            $user = User::factory()->create();
            $user->syncSites([(int) $siteA->getKey()]);
            $this->userIds[] = (int) $user->getKey();

            $token = $user->createToken('epcis-transmit', [SanctumAbilities::EPCIS_TRANSMIT])->plainTextToken;
            $connection = $this->createGlobalConnection();
            $xml = $this->uniqueFixtureXml('tests/Fixtures/epcis/minimal_object_shipping.xml');
            $xml = str_replace('0301160000009', $siteBGln, $xml);

            tenancy()->end();

            $this->tenantApiPost(
                '/api/v1/epcis/outbound?outbound_connection_id='.$connection->getKey(),
                $token,
                $xml,
                ['CONTENT_TYPE' => 'application/xml'],
            )->assertForbidden();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function outbound_duplicate_out_of_scope_returns_generic_409(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Bus::fake([TransmitEpcisJob::class]);

            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);

            $siteAGln = $this->uniqueGln();
            $siteBGln = $this->uniqueGln();

            $siteA = Site::query()->create([
                'name' => 'Outbound Dup Site A '.Str::random(6),
                'gln' => $siteAGln,
                'is_active' => true,
                'is_organization_facility' => true,
                'trading_partner_id' => null,
            ]);
            $siteB = Site::query()->create([
                'name' => 'Outbound Dup Site B '.Str::random(6),
                'gln' => $siteBGln,
                'is_active' => true,
                'is_organization_facility' => true,
                'trading_partner_id' => null,
            ]);
            $this->siteIds = [(int) $siteA->getKey(), (int) $siteB->getKey()];

            $owner = User::factory()->create();
            $owner->assignRole(TenantRole::Owner->value);
            $ownerToken = $owner->createToken('epcis-owner', [SanctumAbilities::EPCIS_TRANSMIT])->plainTextToken;
            $this->userIds[] = (int) $owner->getKey();

            $restricted = User::factory()->create();
            $restricted->syncSites([(int) $siteA->getKey()]);
            $restrictedToken = $restricted->createToken('epcis-restricted', [SanctumAbilities::EPCIS_TRANSMIT])->plainTextToken;
            $this->userIds[] = (int) $restricted->getKey();

            $connection = $this->createGlobalConnection();
            $xml = $this->uniqueFixtureXml('tests/Fixtures/epcis/minimal_object_shipping.xml');
            $xml = str_replace('0301160000009', $siteBGln, $xml);

            tenancy()->end();

            $first = $this->tenantApiPost(
                '/api/v1/epcis/outbound?outbound_connection_id='.$connection->getKey(),
                $ownerToken,
                $xml,
                ['CONTENT_TYPE' => 'application/xml'],
            );
            $first->assertAccepted();

            tenancy()->initialize(Tenant::query()->find(self::DEMO2_TENANT_ID));
            EpcisDocument::query()
                ->whereKey($first->json('document_id'))
                ->update(['ship_from_site_id' => (int) $siteB->getKey()]);
            tenancy()->end();

            $duplicate = $this->tenantApiPost(
                '/api/v1/epcis/outbound?outbound_connection_id='.$connection->getKey(),
                $restrictedToken,
                $xml,
                ['CONTENT_TYPE' => 'application/xml'],
            );

            $duplicate->assertStatus(409)
                ->assertJson([
                    'message' => 'EPCIS document already received.',
                    'duplicate' => true,
                ])
                ->assertJsonMissing(['document_id'])
                ->assertJsonMissing(['document_uuid'])
                ->assertJsonMissing(['status'])
                ->assertJsonMissing(['transmission_status']);

            tenancy()->initialize(Tenant::query()->find(self::DEMO2_TENANT_ID));
            $this->documentIds[] = (int) $first->json('document_id');
            $this->jobIds = EpcisJob::query()
                ->where('epcis_document_id', $first->json('document_id'))
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        } finally {
            $this->cleanup();
        }
    }

    private function uniqueGln(): string
    {
        do {
            $body = '03'.str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
            $gln = $body.\App\Support\Gs1\Gtin::checkDigit($body);
        } while (Site::query()->where('gln', $gln)->exists());

        return $gln;
    }

    private function createGlobalConnection(): OutboundConnection
    {
        $connection = OutboundConnection::query()->create([
            'name' => 'Global API HTTPS '.uniqid(),
            'serialization_provider' => SerializationProvider::CustomHttps,
            'transport' => OutboundTransport::Https,
            'trading_partner_id' => null,
            'is_active' => true,
            'settings' => ['endpoint_url' => 'https://global-api.example/epcis'],
        ]);
        $this->connectionIds[] = (int) $connection->getKey();

        return $connection;
    }

    private function uniqueFixtureXml(string $relativePath, string $uuidPlaceholder = '11111111-2222-3333-4444-555555555555'): string
    {
        $fixture = base_path($relativePath);
        $this->assertFileExists($fixture);

        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);

        return str_replace($uuidPlaceholder, (string) str()->uuid(), $xml);
    }

    private function initializeDemo2Tenant(?TenantProfile $profile = null): Tenant
    {
        $profile ??= TenantProfile::DrugWholesaler;

        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo Wholesaler',
                'profile' => $profile,
                'status' => 'active',
                'tenancy_db_name' => self::DEMO2_DATABASE,
            ]));

            $tenant->domains()->create(['domain' => self::DEMO2_DOMAIN]);
        } else {
            $tenant->domains()->firstOrCreate(['domain' => self::DEMO2_DOMAIN]);
            if ($tenant->profile !== $profile) {
                $tenant->forceFill(['profile' => $profile])->save();
            }
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
            tenancy()->initialize(Tenant::query()->find(self::DEMO2_TENANT_ID));
        }

        if ($this->mdnIds !== []) {
            TransmissionMdn::query()->whereKey($this->mdnIds)->delete();
            $this->mdnIds = [];
        }

        if ($this->jobIds !== []) {
            EpcisJob::query()->whereKey($this->jobIds)->each(function (EpcisJob $job): void {
                $job->messages()->delete();
                $job->delete();
            });
            $this->jobIds = [];
        }

        if ($this->connectionIds !== []) {
            OutboundConnection::query()->whereKey($this->connectionIds)->delete();
            $this->connectionIds = [];
        }

        if ($this->partnerIds !== []) {
            TradingPartner::query()->whereKey($this->partnerIds)->delete();
            $this->partnerIds = [];
        }

        if ($this->documentIds !== []) {
            EpcisDocument::query()->whereKey($this->documentIds)->delete();
            $this->documentIds = [];
        }

        if ($this->userIds !== []) {
            User::query()->whereKey($this->userIds)->delete();
            $this->userIds = [];
        }

        if ($this->siteIds !== []) {
            Site::query()->whereKey($this->siteIds)->delete();
            $this->siteIds = [];
        }

        tenancy()->end();
    }

    /**
     * @param  array<string, string>  $extraServer
     */
    private function tenantApiPost(string $uri, ?string $token, string $body, array $extraServer = []): \Illuminate\Testing\TestResponse
    {
        $path = str_starts_with($uri, '/') ? $uri : '/'.$uri;
        $absolute = 'http://'.self::DEMO2_DOMAIN.$path;

        $server = array_merge([
            'HTTP_HOST' => self::DEMO2_DOMAIN,
            'HTTP_ACCEPT' => 'application/json',
        ], $extraServer);

        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }

        return $this->call(
            'POST',
            $absolute,
            [],
            [],
            [],
            $server,
            $body,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function tenantApiMultipartPost(string $uri, ?string $token, array $data): \Illuminate\Testing\TestResponse
    {
        $path = str_starts_with($uri, '/') ? $uri : '/'.$uri;
        $absolute = 'http://'.self::DEMO2_DOMAIN.$path;

        $server = [
            'HTTP_HOST' => self::DEMO2_DOMAIN,
            'HTTP_ACCEPT' => 'application/json',
        ];

        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }

        return $this->call(
            'POST',
            $absolute,
            [],
            [],
            $data,
            $server,
        );
    }

    private function tenantApiGet(string $uri, ?string $token): \Illuminate\Testing\TestResponse
    {
        $path = str_starts_with($uri, '/') ? $uri : '/'.$uri;
        $absolute = 'http://'.self::DEMO2_DOMAIN.$path;

        $server = [
            'HTTP_HOST' => self::DEMO2_DOMAIN,
            'HTTP_ACCEPT' => 'application/json',
        ];

        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }

        return $this->call(
            'GET',
            $absolute,
            [],
            [],
            [],
            $server,
        );
    }
}
