<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Actions\Portal\EnsurePortalOrganization;
use App\Enums\DataExportStatus;
use App\Enums\OutboundTransport;
use App\Enums\PartnerType;
use App\Enums\SerializationProvider;
use App\Enums\TenantProfile;
use App\Jobs\Exports\ProcessTrackTraceExportJob;
use App\Models\DataExport;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcIlmd;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisException;
use App\Models\Epcis\TransmissionMdn;
use App\Models\OutboundConnection;
use App\Models\PortalOrganization;
use App\Models\PortalPublication;
use App\Models\PortalUser;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Notifications\TrackTraceExportReadyMail;
use App\Services\Epcis\Contracts\OutboundEpcisTransmitter;
use App\Services\Exports\TrackTraceExportQuery;
use App\Services\Exports\TrackTracePdfExporter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ClientPortalTrackTracePdfTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?int $connectionId = null;

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $partnerIds = [];

    /** @var list<int> */
    private array $portalUserIds = [];

    /** @var list<int> */
    private array $portalOrganizationIds = [];

    /** @var list<int> */
    private array $publicationIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<string> */
    private array $exportIds = [];

    /** @var list<string> */
    private array $payloadPaths = [];

    private ?bool $previousClientPortalV2 = null;

    #[Test]
    public function track_trace_streams_pdf_for_error_status_outbound_with_payload(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->enableClientPortalV2($tenant);
        $this->prepareHttpEnvironment();

        try {
            [$portalUser, $document] = $this->seedPublishedShipmentForPortalUser([
                'status' => 'error',
            ]);

            $url = 'http://'.self::DEMO2_DOMAIN.'/client-portal/shipments/'.$document->getKey().'/track-trace';

            tenancy()->end();

            $response = $this->actingAs($portalUser, 'portal')->get($url);

            $response->assertOk();
            $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
            $this->restoreClientPortalV2($tenant);
            tenancy()->end();
        }
    }

    #[Test]
    public function shipments_index_shows_track_trace_actions_for_error_status_with_payload(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->enableClientPortalV2($tenant);
        $this->prepareHttpEnvironment();

        try {
            [$portalUser, $document] = $this->seedPublishedShipmentForPortalUser([
                'status' => 'error',
                'customer_po' => 'PO-PORTAL-ERROR-1',
            ]);

            $indexUrl = 'http://'.self::DEMO2_DOMAIN.'/client-portal/shipments';

            tenancy()->end();

            $this->actingAs($portalUser, 'portal')
                ->get($indexUrl)
                ->assertOk()
                ->assertSee('PO-PORTAL-ERROR-1', false)
                ->assertSee('Track &amp; Trace', false)
                ->assertSee('Serialized T&amp;T', false)
                ->assertDontSee('processed_data.xml', false);
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
            $this->restoreClientPortalV2($tenant);
            tenancy()->end();
        }
    }

    #[Test]
    public function track_trace_streams_pdf_for_visible_publication(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->enableClientPortalV2($tenant);
        $this->prepareHttpEnvironment();

        try {
            [$portalUser, $document] = $this->seedPublishedShipmentForPortalUser();

            $url = 'http://'.self::DEMO2_DOMAIN.'/client-portal/shipments/'.$document->getKey().'/track-trace';

            tenancy()->end();

            $response = $this->actingAs($portalUser, 'portal')->get($url);

            $response->assertOk();
            $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
            $this->assertStringStartsWith('%PDF', $response->streamedContent());
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
            $this->restoreClientPortalV2($tenant);
            tenancy()->end();
        }
    }

    #[Test]
    public function track_trace_forbidden_for_document_not_visible_to_portal_user(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->enableClientPortalV2($tenant);
        $this->prepareHttpEnvironment();

        try {
            $partnerA = $this->createPartner('TT Isolation Org A');
            $partnerB = $this->createPartner('TT Isolation Org B');

            $orgA = app(EnsurePortalOrganization::class)->handle($partnerA);
            $this->portalOrganizationIds[] = (int) $orgA->getKey();
            app(EnsurePortalOrganization::class)->handle($partnerB);

            $portalUser = PortalUser::query()->create([
                'email' => 'tt-iso-a-'.Str::lower(Str::random(8)).'@example.com',
                'is_active' => true,
            ]);
            $this->portalUserIds[] = (int) $portalUser->getKey();
            $orgA->users()->attach($portalUser->getKey(), ['role' => 'member']);

            $connection = OutboundConnection::query()->create([
                'name' => 'Portal TT iso '.Str::random(4),
                'serialization_provider' => SerializationProvider::Other,
                'transport' => OutboundTransport::Portal,
                'trading_partner_id' => $partnerB->getKey(),
                'is_active' => true,
                'settings' => ['notify_on_publish' => false],
            ]);
            $this->connectionId = (int) $connection->getKey();

            $document = $this->createOutboundDocument($connection, $partnerB);
            app(OutboundEpcisTransmitter::class)->transmit($document->fresh());

            $publication = PortalPublication::query()
                ->where('epcis_document_id', $document->getKey())
                ->first();
            $this->assertNotNull($publication);
            $this->publicationIds[] = (int) $publication->getKey();

            $orgB = PortalOrganization::query()
                ->where('trading_partner_id', $partnerB->getKey())
                ->first();
            if ($orgB !== null) {
                $this->portalOrganizationIds[] = (int) $orgB->getKey();
            }

            $url = 'http://'.self::DEMO2_DOMAIN.'/client-portal/shipments/'.$document->getKey().'/track-trace';

            tenancy()->end();

            $this->actingAs($portalUser, 'portal')
                ->get($url)
                ->assertForbidden();
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
            $this->restoreClientPortalV2($tenant);
            tenancy()->end();
        }
    }

    #[Test]
    public function serialized_track_trace_queues_portal_export_and_emails_tenant_domain_link(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->enableClientPortalV2($tenant);
        $this->prepareHttpEnvironment();
        Notification::fake();

        try {
            [$portalUser, $document] = $this->seedPublishedShipmentForPortalUser();
            $this->attachUnitSerials($document, 2);

            // Point exports at a writable disk without clobbering EPCIS payload storage.
            $this->useWritableExportDisk();

            $url = 'http://'.self::DEMO2_DOMAIN.'/client-portal/shipments/'.$document->getKey().'/serialized-track-trace';

            tenancy()->end();

            $response = $this->actingAs($portalUser, 'portal')->post($url);
            $response->assertRedirect();
            $this->assertStringEndsWith(
                '/client-portal/shipments/'.$document->getKey(),
                (string) $response->headers->get('Location'),
            );
            $response->assertSessionHas('status');

            tenancy()->initialize($tenant);
            $this->useWritableExportDisk();

            $export = DataExport::query()
                ->where('notify_email', strtolower((string) $portalUser->email))
                ->latest('created_at')
                ->first();

            $this->assertNotNull($export);
            $this->exportIds[] = (string) $export->getKey();

            $filters = is_array($export->filters) ? $export->filters : [];
            $this->assertTrue((bool) ($filters['portal'] ?? false));
            $this->assertSame((int) $document->getKey(), (int) ($filters['document_id'] ?? 0));
            $this->assertNull($export->requested_by_user_id);

            // Sync queue may have already completed the job during the POST.
            if ($export->status !== DataExportStatus::Completed) {
                $job = new ProcessTrackTraceExportJob(self::DEMO2_TENANT_ID, (string) $export->getKey());
                $job->handle(
                    app(TrackTraceExportQuery::class),
                    app(TrackTracePdfExporter::class),
                );
                tenancy()->initialize($tenant);
                $this->useWritableExportDisk();
                $export->refresh();
            }

            $this->assertSame(DataExportStatus::Completed, $export->status, (string) $export->error_message);
            $this->assertGreaterThan(0, (int) $export->row_count);

            // Simulate demo deploy where APP_URL is the central/admin host.
            config(['app.url' => 'https://admin2.internal.vatengi.com']);
            URL::forceRootUrl('https://admin2.internal.vatengi.com');

            $downloadUrl = $export->temporaryDownloadUrl(tenantId: self::DEMO2_TENANT_ID);
            $this->assertNotEmpty($downloadUrl);
            $this->assertStringContainsString(self::DEMO2_DOMAIN, (string) $downloadUrl);
            $this->assertStringNotContainsString('admin2.internal.vatengi.com', (string) $downloadUrl);

            Notification::assertSentOnDemand(
                TrackTraceExportReadyMail::class,
                function (TrackTraceExportReadyMail $notification) use ($export): bool {
                    return (string) $notification->export->getKey() === (string) $export->getKey()
                        && $notification->tenantId === self::DEMO2_TENANT_ID;
                },
            );
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
            $this->restoreClientPortalV2($tenant);
            tenancy()->end();
        }
    }

    /**
     * @param  array<string, mixed>  $documentAttributes
     * @return array{0: PortalUser, 1: EpcisDocument}
     */
    private function seedPublishedShipmentForPortalUser(array $documentAttributes = []): array
    {
        $partner = $this->createPartner('Portal TT Buyer');
        $org = app(EnsurePortalOrganization::class)->handle($partner);
        $this->portalOrganizationIds[] = (int) $org->getKey();

        $portalUser = PortalUser::query()->create([
            'email' => 'tt-buyer-'.Str::lower(Str::random(8)).'@example.com',
            'is_active' => true,
        ]);
        $this->portalUserIds[] = (int) $portalUser->getKey();
        $org->users()->attach($portalUser->getKey(), ['role' => 'member']);

        $connection = OutboundConnection::query()->create([
            'name' => 'Portal TT '.Str::random(4),
            'serialization_provider' => SerializationProvider::Other,
            'transport' => OutboundTransport::Portal,
            'trading_partner_id' => $partner->getKey(),
            'is_active' => true,
            'settings' => ['notify_on_publish' => false],
        ]);
        $this->connectionId = (int) $connection->getKey();

        $document = $this->createOutboundDocument($connection, $partner, array_merge([
            'asn_number' => 'ASN-TT-1',
            'customer_po' => 'PO-TT-1',
        ], $documentAttributes));
        app(OutboundEpcisTransmitter::class)->transmit($document->fresh());

        if (isset($documentAttributes['status'])) {
            EpcisDocument::query()
                ->whereKey($document->getKey())
                ->update(['status' => $documentAttributes['status']]);
        }

        $publication = PortalPublication::query()
            ->where('epcis_document_id', $document->getKey())
            ->first();
        $this->assertNotNull($publication);
        $this->publicationIds[] = (int) $publication->getKey();

        return [$portalUser, $document->fresh()];
    }

    private function attachUnitSerials(EpcisDocument $document, int $count): void
    {
        $generation = (int) ($document->ingest_generation ?? 1);

        for ($i = 0; $i < $count; $i++) {
            $serial = 'portalt'.$i.Str::lower(Str::random(6));
            $epc = Epc::query()->create([
                'epc_uri' => 'urn:epc:id:sgtin:030116.0200116.'.$serial,
                'epc_type' => 'sgtin',
                'company_prefix' => '030116',
                'gtin14' => '00301162001162',
                'serial_number' => $serial,
                'ai_01_21' => '010030116200116221'.$serial,
                'first_seen_at' => now(),
            ]);
            $this->epcIds[] = (int) $epc->id;

            EpcIlmd::query()->create([
                'epc_id' => $epc->id,
                'lot_number' => 'LOT-PORTAL-TT',
                'gtin14' => '00301162001162',
                'expiry_date' => '2029-05-31',
            ]);

            if (Schema::hasTable('document_epcs')) {
                DB::table('document_epcs')->insert([
                    'document_id' => $document->id,
                    'epc_id' => $epc->id,
                    'ingest_generation' => $generation,
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function createPartner(string $name, array $extra = []): TradingPartner
    {
        $partner = TradingPartner::query()->create(array_merge([
            'name' => $name.' '.uniqid(),
            'gln' => $this->uniqueGln(),
            'partner_type' => PartnerType::Pharmacy,
            'country_code' => 'US',
            'is_active' => true,
        ], $extra));
        $this->partnerIds[] = (int) $partner->getKey();

        return $partner;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createOutboundDocument(
        OutboundConnection $connection,
        TradingPartner $partner,
        array $attributes = [],
    ): EpcisDocument {
        $xml = $this->schemaValidOutboundXml();
        $path = 'epcis/outbound/portal-tt-'.Str::uuid().'.xml';
        Storage::disk('local')->put($path, $xml);
        $this->payloadPaths[] = $path;

        $document = EpcisDocument::query()->create(array_merge([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'direction' => 'outbound',
            'format' => 'xml',
            'original_filename' => 'portal-tt-shipment.xml',
            'payload_disk' => 'local',
            'payload_path' => $path,
            'file_sha256' => hash('sha256', $xml),
            'dscsa_affirm' => true,
            'status' => 'parsed',
            'reprocess_count' => 0,
            'event_count' => 1,
            'epc_count' => 1,
            'received_at' => now(),
            'outbound_connection_id' => $connection->getKey(),
            'trading_partner_id' => $partner->getKey(),
            'ingest_generation' => 1,
        ], $attributes));
        $this->documentIds[] = (int) $document->getKey();

        return $document;
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

    private function uniqueGln(): string
    {
        $base = str_pad((string) random_int(100000000000, 899999999999), 12, '0', STR_PAD_LEFT);
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $digit = (int) $base[$i];
            $sum += ($i % 2 === 0) ? $digit : $digit * 3;
        }
        $check = (10 - ($sum % 10)) % 10;

        return $base.$check;
    }

    private function useWritableExportDisk(): void
    {
        $root = '/tmp/tracepharma-portal-exports-'.getmypid();
        if (! is_dir($root)) {
            mkdir($root, 0777, true);
        }

        config([
            'tracepharma.exports.disk' => 'tenant_exports',
            'filesystems.disks.tenant_exports.driver' => 'local',
            'filesystems.disks.tenant_exports.root' => $root,
            'filesystems.disks.tenant_exports.throw' => true,
        ]);
        Storage::forgetDisk('tenant_exports');
    }

    private function prepareHttpEnvironment(): void
    {
        $compiled = sys_get_temp_dir().'/tracepharma-client-portal-tt-views-'.getmypid();
        if (! is_dir($compiled)) {
            mkdir($compiled, 0777, true);
        }

        config([
            'logging.default' => 'null',
            'view.compiled' => $compiled,
        ]);

        $this->app->forgetInstance('blade.compiler');
        $this->app->forgetInstance('view');

        URL::forceRootUrl('http://'.self::DEMO2_DOMAIN);
    }

    private function enableClientPortalV2(Tenant $tenant): void
    {
        $settings = $tenant->settings ?? [];
        if (! is_array($settings)) {
            $settings = [];
        }

        $this->previousClientPortalV2 = (bool) data_get($settings, 'features.client_portal_v2', false);
        data_set($settings, 'features.client_portal_v2', true);
        $tenant->setAttribute('settings', $settings);
        $tenant->save();

        if (tenancy()->initialized) {
            tenancy()->end();
            tenancy()->initialize($tenant->fresh());
        }
    }

    private function restoreClientPortalV2(Tenant $tenant): void
    {
        if ($this->previousClientPortalV2 === null) {
            return;
        }

        $fresh = $tenant->fresh() ?? $tenant;
        $settings = $fresh->settings ?? [];
        if (! is_array($settings)) {
            $settings = [];
        }

        data_set($settings, 'features.client_portal_v2', $this->previousClientPortalV2);
        $fresh->setAttribute('settings', $settings);
        $fresh->save();
        $this->previousClientPortalV2 = null;
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

        if ($this->exportIds !== []) {
            DataExport::query()->whereIn('id', $this->exportIds)->delete();
        }

        if ($this->epcIds !== [] && Schema::hasTable('document_epcs')) {
            DB::table('document_epcs')->whereIn('epc_id', $this->epcIds)->delete();
        }

        if ($this->epcIds !== []) {
            EpcIlmd::query()->whereIn('epc_id', $this->epcIds)->delete();
            Epc::query()->whereIn('id', $this->epcIds)->delete();
        }

        if ($this->publicationIds !== []) {
            PortalPublication::query()->whereIn('id', $this->publicationIds)->delete();
        } elseif ($this->documentIds !== []) {
            PortalPublication::query()->whereIn('epcis_document_id', $this->documentIds)->delete();
        }

        if ($this->portalOrganizationIds !== []) {
            DB::table('portal_organization_user')
                ->whereIn('portal_organization_id', $this->portalOrganizationIds)
                ->delete();
            PortalOrganization::query()
                ->whereIn('id', $this->portalOrganizationIds)
                ->delete();
        }

        if ($this->portalUserIds !== []) {
            PortalUser::query()->whereIn('id', $this->portalUserIds)->delete();
        }

        if ($this->documentIds !== []) {
            if (Schema::hasTable('document_epcs')) {
                DB::table('document_epcs')->whereIn('document_id', $this->documentIds)->delete();
            }
            EpcisException::query()->whereIn('document_id', $this->documentIds)->delete();
            TransmissionMdn::query()->whereIn('document_id', $this->documentIds)->delete();
            if (Schema::hasTable('epcis_events')) {
                DB::table('epcis_events')->whereIn('document_id', $this->documentIds)->delete();
            }
            EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
        }

        foreach ($this->payloadPaths as $path) {
            try {
                Storage::disk('local')->delete($path);
            } catch (\Throwable) {
                // Export disk override may point elsewhere; ignore missing payloads.
            }
        }

        if ($this->connectionId !== null) {
            OutboundConnection::query()->whereKey($this->connectionId)->delete();
        }

        if ($this->partnerIds !== []) {
            PortalOrganization::query()
                ->whereIn('trading_partner_id', $this->partnerIds)
                ->delete();
            TradingPartner::query()->whereIn('id', $this->partnerIds)->delete();
        }

        $this->documentIds = [];
        $this->partnerIds = [];
        $this->portalUserIds = [];
        $this->portalOrganizationIds = [];
        $this->publicationIds = [];
        $this->epcIds = [];
        $this->exportIds = [];
        $this->payloadPaths = [];
        $this->connectionId = null;
    }
}
