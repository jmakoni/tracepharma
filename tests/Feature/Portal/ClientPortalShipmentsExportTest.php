<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Actions\Portal\EnsurePortalOrganization;
use App\Enums\OutboundTransport;
use App\Enums\PartnerType;
use App\Enums\SerializationProvider;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Epcis\EpcisException;
use App\Models\Epcis\TransmissionMdn;
use App\Models\OutboundConnection;
use App\Models\PortalOrganization;
use App\Models\PortalPublication;
use App\Models\PortalUser;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Services\Epcis\Contracts\OutboundEpcisTransmitter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ClientPortalShipmentsExportTest extends TestCase
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

    /** @var list<int> */
    private array $eventIds = [];

    /** @var list<string> */
    private array $payloadPaths = [];

    private ?bool $previousClientPortalV2 = null;

    #[Test]
    public function summary_csv_export_includes_po_and_asn_columns_for_visible_publication(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->enableClientPortalV2($tenant);
        $this->prepareHttpEnvironment();

        try {
            [$portalUser] = $this->seedPublishedShipmentForPortalUser([
                'customer_po' => 'PO-EXPORT-001',
                'asn_number' => 'ASN-EXPORT-001',
            ]);

            $url = 'http://'.self::DEMO2_DOMAIN.'/client-portal/shipments/export?grain=summary&format=csv';

            tenancy()->end();

            $response = $this->actingAs($portalUser, 'portal')->get($url);

            $response->assertOk();
            $this->assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
            $body = $response->streamedContent();
            $this->assertStringContainsString('Customer PO', $body);
            $this->assertStringContainsString('ASN Number', $body);
            $this->assertStringContainsString('PO-EXPORT-001', $body);
            $this->assertStringContainsString('ASN-EXPORT-001', $body);
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
    public function export_forbidden_for_document_not_visible_to_portal_user(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->enableClientPortalV2($tenant);
        $this->prepareHttpEnvironment();

        try {
            $partnerA = $this->createPartner('Export Isolation Org A');
            $partnerB = $this->createPartner('Export Isolation Org B');

            $orgA = app(EnsurePortalOrganization::class)->handle($partnerA);
            $this->portalOrganizationIds[] = (int) $orgA->getKey();
            app(EnsurePortalOrganization::class)->handle($partnerB);

            $portalUser = PortalUser::query()->create([
                'email' => 'export-iso-'.Str::lower(Str::random(8)).'@example.com',
                'is_active' => true,
            ]);
            $this->portalUserIds[] = (int) $portalUser->getKey();
            $orgA->users()->attach($portalUser->getKey(), ['role' => 'member']);

            $connection = OutboundConnection::query()->create([
                'name' => 'Portal export iso '.Str::random(4),
                'serialization_provider' => SerializationProvider::Other,
                'transport' => OutboundTransport::Portal,
                'trading_partner_id' => $partnerB->getKey(),
                'is_active' => true,
                'settings' => ['notify_on_publish' => false],
            ]);
            $this->connectionId = (int) $connection->getKey();

            [, $document] = $this->seedPublishedShipmentForPortalUser([], $connection, $partnerB);

            $url = 'http://'.self::DEMO2_DOMAIN.'/client-portal/shipments/'.$document->getKey().'/export?grain=lines&format=csv';

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
    public function line_csv_export_includes_pms_intake_serial_columns(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->enableClientPortalV2($tenant);
        $this->prepareHttpEnvironment();

        try {
            [$portalUser, $document] = $this->seedPublishedShipmentForPortalUser([
                'customer_po' => 'PO-LINE-001',
            ]);

            $epcUri = 'urn:epc:id:sgtin:030116.0200116.'.random_int(10000000000000, 99999999999999);
            $epc = Epc::fromUri($epcUri);
            $epc->save();
            $this->epcIds[] = (int) $epc->getKey();

            $event = EpcisEvent::query()->create([
                'document_id' => $document->getKey(),
                'event_type' => 'ObjectEvent',
                'event_time' => now()->subHour(),
                'action' => 'OBSERVE',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:shipping',
                'disposition' => 'urn:epcglobal:cbv:disp:in_transit',
            ]);
            $this->eventIds[] = (int) $event->getKey();

            DB::table('event_epcs')->insert([
                'event_id' => $event->getKey(),
                'epc_id' => $epc->getKey(),
                'role' => 'epcList',
            ]);

            if (Schema::hasTable('epc_ilmd')) {
                DB::table('epc_ilmd')->insert([
                    'epc_id' => $epc->getKey(),
                    'gtin14' => $epc->gtin14,
                    'lot_number' => 'LOT-EXPORT-1',
                    'expiry_date' => '2029-12-31',
                ]);
            }

            $url = 'http://'.self::DEMO2_DOMAIN.'/client-portal/shipments/export?grain=lines&format=csv';

            tenancy()->end();

            $response = $this->actingAs($portalUser, 'portal')->get($url);

            $response->assertOk();
            $body = $response->streamedContent();
            $this->assertStringContainsString('GTIN-14', $body);
            $this->assertStringContainsString('Serial Number', $body);
            $this->assertStringContainsString('Lot Number', $body);
            $this->assertStringContainsString('LOT-EXPORT-1', $body);
            $this->assertStringContainsString((string) $epc->serial_number, $body);
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
    public function export_respects_po_filter(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->enableClientPortalV2($tenant);
        $this->prepareHttpEnvironment();

        try {
            [$portalUser, $firstDocument] = $this->seedPublishedShipmentForPortalUser([
                'customer_po' => 'PO-MATCH-123',
            ]);
            $partner = TradingPartner::query()->findOrFail($firstDocument->trading_partner_id);
            $connection = OutboundConnection::query()->findOrFail($firstDocument->outbound_connection_id);
            $this->seedPublishedShipmentForPortalUser(
                ['customer_po' => 'PO-OTHER-999'],
                $connection,
                $partner,
                $portalUser,
            );

            $url = 'http://'.self::DEMO2_DOMAIN.'/client-portal/shipments/export?grain=summary&format=csv&po=PO-MATCH-123';

            tenancy()->end();

            $response = $this->actingAs($portalUser, 'portal')->get($url);

            $response->assertOk();
            $body = $response->streamedContent();
            $this->assertStringContainsString('PO-MATCH-123', $body);
            $this->assertStringNotContainsString('PO-OTHER-999', $body);
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
    public function export_redirects_with_error_when_row_limit_exceeded(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->enableClientPortalV2($tenant);
        $this->prepareHttpEnvironment();
        config(['advanced-table-export-for-filament.max_export_rows' => 1]);

        try {
            [$portalUser, $document] = $this->seedPublishedShipmentForPortalUser(['customer_po' => 'PO-LIMIT-1']);

            foreach (range(1, 2) as $index) {
                $epcUri = 'urn:epc:id:sgtin:030116.0200116.'.(10000000000000 + $index);
                $epc = Epc::fromUri($epcUri);
                $epc->save();
                $this->epcIds[] = (int) $epc->getKey();

                $event = EpcisEvent::query()->create([
                    'document_id' => $document->getKey(),
                    'event_type' => 'ObjectEvent',
                    'event_time' => now()->subMinutes($index),
                    'action' => 'OBSERVE',
                    'biz_step' => 'urn:epcglobal:cbv:bizstep:shipping',
                    'disposition' => 'urn:epcglobal:cbv:disp:in_transit',
                ]);
                $this->eventIds[] = (int) $event->getKey();

                DB::table('event_epcs')->insert([
                    'event_id' => $event->getKey(),
                    'epc_id' => $epc->getKey(),
                    'role' => 'epcList',
                ]);
            }

            $url = 'http://'.self::DEMO2_DOMAIN.'/client-portal/shipments/export?grain=lines&format=csv';

            tenancy()->end();

            $this->actingAs($portalUser, 'portal')
                ->get($url)
                ->assertRedirect('http://'.self::DEMO2_DOMAIN.'/client-portal/shipments')
                ->assertSessionHas('error');
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            config(['advanced-table-export-for-filament.max_export_rows' => 2000]);
            $this->cleanup();
            $this->restoreClientPortalV2($tenant);
            tenancy()->end();
        }
    }

    /**
     * @param  array<string, mixed>  $documentAttributes
     * @return array{0: PortalUser, 1: EpcisDocument}
     */
    private function seedPublishedShipmentForPortalUser(
        array $documentAttributes = [],
        ?OutboundConnection $connection = null,
        ?TradingPartner $partner = null,
        ?PortalUser $portalUser = null,
    ): array {
        $partner ??= $this->createPartner('Portal Export Buyer');

        if ($portalUser === null) {
            $org = app(EnsurePortalOrganization::class)->handle($partner);
            $this->portalOrganizationIds[] = (int) $org->getKey();

            $portalUser = PortalUser::query()->create([
                'email' => 'export-buyer-'.Str::lower(Str::random(8)).'@example.com',
                'is_active' => true,
            ]);
            $this->portalUserIds[] = (int) $portalUser->getKey();
            $org->users()->attach($portalUser->getKey(), ['role' => 'member']);
        }

        $connection ??= OutboundConnection::query()->create([
            'name' => 'Portal export '.Str::random(4),
            'serialization_provider' => SerializationProvider::Other,
            'transport' => OutboundTransport::Portal,
            'trading_partner_id' => $partner->getKey(),
            'is_active' => true,
            'settings' => ['notify_on_publish' => false],
        ]);
        $this->connectionId = (int) $connection->getKey();

        $document = $this->createOutboundDocument($connection, $partner, $documentAttributes);
        app(OutboundEpcisTransmitter::class)->transmit($document->fresh());

        $publication = PortalPublication::query()
            ->where('epcis_document_id', $document->getKey())
            ->first();
        $this->assertNotNull($publication);
        $this->publicationIds[] = (int) $publication->getKey();

        return [$portalUser, $document->fresh()];
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
        $path = 'epcis/outbound/portal-export-'.Str::uuid().'.xml';
        Storage::disk('local')->put($path, $xml);
        $this->payloadPaths[] = $path;

        $document = EpcisDocument::query()->create(array_merge([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'direction' => 'outbound',
            'format' => 'xml',
            'original_filename' => 'portal-export-shipment.xml',
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

    private function prepareHttpEnvironment(): void
    {
        $compiled = sys_get_temp_dir().'/tracepharma-portal-export-views-'.getmypid();
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

        if ($this->eventIds !== [] && Schema::hasTable('event_epcs')) {
            DB::table('event_epcs')->whereIn('event_id', $this->eventIds)->delete();
        }
        if ($this->eventIds !== [] && Schema::hasTable('epcis_events')) {
            EpcisEvent::query()->whereIn('id', $this->eventIds)->delete();
        }
        if ($this->epcIds !== []) {
            if (Schema::hasTable('epc_ilmd')) {
                DB::table('epc_ilmd')->whereIn('epc_id', $this->epcIds)->delete();
            }
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
            EpcisException::query()->whereIn('document_id', $this->documentIds)->delete();
            TransmissionMdn::query()->whereIn('document_id', $this->documentIds)->delete();
            if (Schema::hasTable('epcis_events')) {
                DB::table('epcis_events')->whereIn('document_id', $this->documentIds)->delete();
            }
            EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
        }

        foreach ($this->payloadPaths as $path) {
            Storage::disk('local')->delete($path);
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
        $this->eventIds = [];
        $this->payloadPaths = [];
        $this->connectionId = null;
    }
}
