<?php

namespace Tests\Feature\Receiving;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Receiving\OpenReceivingSessionFromDocument;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisException;
use App\Models\Receiving\InboundShipment;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Tenant;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InboundAsnShipmentGroupingTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const DEFAULT_SSCC = 'urn:epc:id:sscc:030116.01001227052';

    private const DEFAULT_SGTIN = 'urn:epc:id:sgtin:030116.0200116.10000082001560';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<string> */
    private array $epcUris = [];

    #[Test]
    public function two_inbound_files_same_partner_asn_share_one_shipment_and_raise_file_added(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->assertTrue(Schema::hasTable('inbound_shipments'));

            $suffixA = (string) random_int(100000, 999999);
            $suffixB = (string) random_int(100000, 999999);
            $asn = 'ASN-GRP-'.$suffixA;

            $docA = $this->ingestShippingRefsFixture(
                ssccUri: 'urn:epc:id:sscc:030116.01001'.$suffixA,
                sgtinUri: 'urn:epc:id:sgtin:030116.0200116.1'.$suffixA,
                asn: $asn,
                po: 'PO-GRP-'.$suffixA,
            );
            $docB = $this->ingestShippingRefsFixture(
                ssccUri: 'urn:epc:id:sscc:030116.01001'.$suffixB,
                sgtinUri: 'urn:epc:id:sgtin:030116.0200116.1'.$suffixB,
                asn: $asn,
                po: 'PO-GRP-'.$suffixA,
            );

            $this->assertNotNull($docA->inbound_shipment_id);
            $this->assertSame($docA->inbound_shipment_id, $docB->inbound_shipment_id);
            $this->assertSame(2, (int) InboundShipment::query()->findOrFail($docA->inbound_shipment_id)->document_count);

            $this->assertFalse(
                EpcisException::query()
                    ->where('document_id', $docA->getKey())
                    ->where('exception_type', 'ASN_SHIPMENT_FILE_ADDED')
                    ->where('status', 'open')
                    ->exists(),
            );
            $this->assertTrue(
                EpcisException::query()
                    ->where('document_id', $docB->getKey())
                    ->where('exception_type', 'ASN_SHIPMENT_FILE_ADDED')
                    ->where('status', 'open')
                    ->exists(),
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function open_receive_from_either_member_reuses_session_with_union_roots(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $suffixA = (string) random_int(100000, 999999);
            $suffixB = (string) random_int(100000, 999999);
            $asn = 'ASN-UNION-'.$suffixA;
            $ssccA = 'urn:epc:id:sscc:030116.01002'.$suffixA;
            $ssccB = 'urn:epc:id:sscc:030116.01002'.$suffixB;

            $docA = $this->ingestShippingRefsFixture(
                ssccUri: $ssccA,
                sgtinUri: 'urn:epc:id:sgtin:030116.0200116.2'.$suffixA,
                asn: $asn,
                po: 'PO-UNION-'.$suffixA,
            );
            $docB = $this->ingestShippingRefsFixture(
                ssccUri: $ssccB,
                sgtinUri: 'urn:epc:id:sgtin:030116.0200116.2'.$suffixB,
                asn: $asn,
                po: 'PO-UNION-'.$suffixA,
            );

            $sessionA = app(OpenReceivingSessionFromDocument::class)->handle($docA);
            $sessionB = app(OpenReceivingSessionFromDocument::class)->handle($docB);

            $this->assertSame($sessionA->id, $sessionB->id);
            $this->assertSame($docA->inbound_shipment_id, $sessionA->inbound_shipment_id);
            $this->assertSame(2, (int) $sessionA->fresh()->expected_parent_count);

            $parentUris = ReceivingScanLine::query()
                ->where('receiving_session_id', $sessionA->id)
                ->where('line_role', 'parent')
                ->with('epc')
                ->get()
                ->pluck('epc.epc_uri')
                ->filter()
                ->values()
                ->all();

            $expected = [$ssccA, $ssccB];
            sort($expected);
            sort($parentUris);
            $this->assertSame($expected, $parentUris);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function late_file_expands_expected_parents_on_open_session(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $suffixA = (string) random_int(100000, 999999);
            $suffixB = (string) random_int(100000, 999999);
            $asn = 'ASN-LATE-'.$suffixA;
            $ssccA = 'urn:epc:id:sscc:030116.01003'.$suffixA;
            $ssccB = 'urn:epc:id:sscc:030116.01003'.$suffixB;

            $docA = $this->ingestShippingRefsFixture(
                ssccUri: $ssccA,
                sgtinUri: 'urn:epc:id:sgtin:030116.0200116.3'.$suffixA,
                asn: $asn,
                po: 'PO-LATE-'.$suffixA,
            );

            $session = app(OpenReceivingSessionFromDocument::class)->handle($docA);
            $this->assertSame(1, (int) $session->expected_parent_count);

            $docB = $this->ingestShippingRefsFixture(
                ssccUri: $ssccB,
                sgtinUri: 'urn:epc:id:sgtin:030116.0200116.3'.$suffixB,
                asn: $asn,
                po: 'PO-LATE-'.$suffixA,
            );

            $session->refresh();
            $this->assertSame(2, (int) $session->expected_parent_count);
            $this->assertTrue(
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $session->id)
                    ->where('line_role', 'parent')
                    ->whereHas('epc', fn ($q) => $q->where('epc_uri', $ssccB))
                    ->exists(),
            );
            $this->assertTrue(
                EpcisException::query()
                    ->where('document_id', $docB->getKey())
                    ->where('exception_type', 'ASN_SHIPMENT_FILE_ADDED')
                    ->exists(),
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function same_asn_different_nonblank_po_does_not_join(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $suffixA = (string) random_int(100000, 999999);
            $suffixB = (string) random_int(100000, 999999);
            $asn = 'ASN-POM-'.$suffixA;

            $docA = $this->ingestShippingRefsFixture(
                ssccUri: 'urn:epc:id:sscc:030116.01004'.$suffixA,
                sgtinUri: 'urn:epc:id:sgtin:030116.0200116.4'.$suffixA,
                asn: $asn,
                po: 'PO-ONE-'.$suffixA,
            );
            $docB = $this->ingestShippingRefsFixture(
                ssccUri: 'urn:epc:id:sscc:030116.01004'.$suffixB,
                sgtinUri: 'urn:epc:id:sgtin:030116.0200116.4'.$suffixB,
                asn: $asn,
                po: 'PO-TWO-'.$suffixB,
            );

            $this->assertNotNull($docA->inbound_shipment_id);
            $this->assertNull($docB->inbound_shipment_id);
            $this->assertTrue(
                EpcisException::query()
                    ->where('document_id', $docB->getKey())
                    ->where('exception_type', 'ASN_SHIPMENT_PO_MISMATCH')
                    ->where('status', 'open')
                    ->exists(),
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function blank_asn_does_not_create_shipment(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $suffix = (string) random_int(100000, 999999);
            $doc = $this->ingestMinimalObjectShipping(
                ssccUri: 'urn:epc:id:sscc:030116.01005'.$suffix,
                sgtinUri: 'urn:epc:id:sgtin:030116.0200116.5'.$suffix,
            );

            $this->assertTrue(blank($doc->asn_number));
            $this->assertNull($doc->inbound_shipment_id);
        } finally {
            $this->cleanup();
        }
    }

    private function ingestShippingRefsFixture(
        string $ssccUri,
        string $sgtinUri,
        string $asn,
        string $po,
    ): EpcisDocument {
        $fixture = base_path('tests/Fixtures/epcis/minimal_with_shipping_refs.xml');
        $this->assertFileExists($fixture);

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_asn_');
        $this->assertNotFalse($tmp);
        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);
        $uuid = (string) str()->uuid();
        $xml = str_replace('22222222-3333-4444-5555-666666666666', $uuid, $xml);
        $xml = str_replace(self::DEFAULT_SSCC, $ssccUri, $xml);
        $xml = str_replace(self::DEFAULT_SGTIN, $sgtinUri, $xml);
        $xml = str_replace('ASN-TEST-4787', $asn, $xml);
        $xml = str_replace('PO-TEST-7174', $po, $xml);
        file_put_contents($tmp, $xml);

        $this->epcUris[] = $ssccUri;
        $this->epcUris[] = $sgtinUri;

        try {
            $document = app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'minimal_with_shipping_refs.xml',
            ]);
        } finally {
            @unlink($tmp);
        }

        $this->documentIds[] = (int) $document->getKey();
        $this->assertSame('validated', $document->status);

        return $document->fresh();
    }

    private function ingestMinimalObjectShipping(string $ssccUri, string $sgtinUri): EpcisDocument
    {
        $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
        $this->assertFileExists($fixture);

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_blank_');
        $this->assertNotFalse($tmp);
        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);
        $uuid = (string) str()->uuid();
        $xml = str_replace('11111111-2222-3333-4444-555555555555', $uuid, $xml);
        $xml = str_replace(self::DEFAULT_SSCC, $ssccUri, $xml);
        $xml = str_replace(self::DEFAULT_SGTIN, $sgtinUri, $xml);
        file_put_contents($tmp, $xml);

        $this->epcUris[] = $ssccUri;
        $this->epcUris[] = $sgtinUri;

        try {
            $document = app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'minimal_object_shipping.xml',
            ]);
        } finally {
            @unlink($tmp);
        }

        $this->documentIds[] = (int) $document->getKey();

        return $document->fresh();
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

        try {
            $shipmentIds = EpcisDocument::query()
                ->whereIn('id', $this->documentIds)
                ->whereNotNull('inbound_shipment_id')
                ->pluck('inbound_shipment_id')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->all();

            $sessionQuery = ReceivingSession::query()->whereIn('epcis_document_id', $this->documentIds);
            if ($shipmentIds !== [] && Schema::hasColumn('receiving_sessions', 'inbound_shipment_id')) {
                $sessionQuery->orWhereIn('inbound_shipment_id', $shipmentIds);
            }

            foreach ($sessionQuery->get() as $session) {
                ReceivingScanLine::query()->where('receiving_session_id', $session->id)->delete();
                $session->delete();
            }

            EpcisException::query()->whereIn('document_id', $this->documentIds)->delete();

            if ($shipmentIds !== []) {
                EpcisDocument::query()
                    ->whereIn('inbound_shipment_id', $shipmentIds)
                    ->update(['inbound_shipment_id' => null]);
                InboundShipment::query()->whereIn('id', $shipmentIds)->delete();
            }

            // Documents/events/EPCs stay; unique ASN/EPC suffixes avoid collisions.
        } finally {
            $this->documentIds = [];
            $this->epcUris = [];
            tenancy()->end();
        }
    }
}
