<?php

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\ReceiveEpcisUpload;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisException;
use App\Models\Tenant;
use App\Services\Epcis\EpcisIngestionService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Commissioning and packing are separate authored documents, so an ORPHAN_SSCC
 * signal raised when the SSCC is commissioned goes stale once a later packing
 * AggregationEvent makes that SSCC an aggregation parent. ProcessEpcisDocument
 * must resolve the open signal when the first parent link is established.
 */
class OrphanSsccAutoResolveTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<string> */
    private array $epcUris = [];

    #[Test]
    public function aggregation_add_resolves_open_orphan_sscc_signal(): void
    {
        $this->initializeDemo2Tenant();

        $serial = (string) random_int(1000000000, 9999999999);
        $ssccUri = 'urn:epc:id:sscc:030116.0'.$serial;
        $childUri = 'urn:epc:id:sgtin:030116.0200116.'.$serial;
        $this->epcUris = [$ssccUri, $childUri];

        try {
            $commissionDoc = $this->processXml($this->commissioningXml($ssccUri), 'orphan-commission.xml');

            $ssccEpcId = (int) Epc::query()->where('epc_uri', $ssccUri)->value('id');
            $this->assertGreaterThan(0, $ssccEpcId);

            $this->assertTrue(
                EpcisException::query()
                    ->where('document_id', $commissionDoc->getKey())
                    ->where('exception_type', 'ORPHAN_SSCC')
                    ->where('epc_id', $ssccEpcId)
                    ->where('status', 'open')
                    ->exists(),
                'Commissioning-only document must raise an open ORPHAN_SSCC signal',
            );

            $this->processXml($this->packingXml($ssccUri, $childUri), 'orphan-pack.xml');

            $orphan = EpcisException::query()
                ->where('document_id', $commissionDoc->getKey())
                ->where('exception_type', 'ORPHAN_SSCC')
                ->where('epc_id', $ssccEpcId)
                ->first();

            $this->assertNotNull($orphan);
            $this->assertSame('resolved', $orphan->status, 'Packing ADD must resolve the stale ORPHAN_SSCC signal');
            $this->assertNotNull($orphan->resolved_at);
        } finally {
            $this->cleanup();
        }
    }

    private function processXml(string $xml, string $filename): EpcisDocument
    {
        $tmp = tempnam(sys_get_temp_dir(), 'epcis_orphan_').'.xml';
        file_put_contents($tmp, $xml);

        $document = app(ReceiveEpcisUpload::class)->handle($tmp, [
            'direction' => 'inbound',
            'original_filename' => $filename,
            'dispatch' => false,
        ]);
        $this->documentIds[] = (int) $document->getKey();

        app(EpcisIngestionService::class)->process($document);

        @unlink($tmp);

        return $document;
    }

    private function commissioningXml(string $ssccUri): string
    {
        $instanceId = (string) str()->uuid();

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<epcis:EPCISDocument
    xmlns:epcis="urn:epcglobal:epcis:xsd:1"
    xmlns:sbdh="http://www.unece.org/cefact/namespaces/StandardBusinessDocumentHeader"
    schemaVersion="1.2"
    creationDate="2026-07-15T23:06:33.411Z">
  <EPCISHeader>
    <sbdh:StandardBusinessDocumentHeader>
      <sbdh:HeaderVersion>1.0</sbdh:HeaderVersion>
      <sbdh:Sender>
        <sbdh:Identifier Authority="GLN">0301160000009</sbdh:Identifier>
      </sbdh:Sender>
      <sbdh:Receiver>
        <sbdh:Identifier Authority="GLN">0860249001509</sbdh:Identifier>
      </sbdh:Receiver>
      <sbdh:DocumentIdentification>
        <sbdh:Standard>EPCglobal</sbdh:Standard>
        <sbdh:TypeVersion>1.0</sbdh:TypeVersion>
        <sbdh:InstanceIdentifier>{$instanceId}</sbdh:InstanceIdentifier>
        <sbdh:Type>Events</sbdh:Type>
        <sbdh:CreationDateAndTime>2026-07-15T23:06:33.411Z</sbdh:CreationDateAndTime>
      </sbdh:DocumentIdentification>
    </sbdh:StandardBusinessDocumentHeader>
  </EPCISHeader>
  <EPCISBody>
    <EventList>
      <ObjectEvent>
        <eventTime>2026-07-06T21:12:18.697Z</eventTime>
        <eventTimeZoneOffset>+00:00</eventTimeZoneOffset>
        <epcList>
          <epc>{$ssccUri}</epc>
        </epcList>
        <action>ADD</action>
        <bizStep>urn:epcglobal:cbv:bizstep:commissioning</bizStep>
        <disposition>urn:epcglobal:cbv:disp:active</disposition>
      </ObjectEvent>
    </EventList>
  </EPCISBody>
</epcis:EPCISDocument>
XML;
    }

    private function packingXml(string $ssccUri, string $childUri): string
    {
        $instanceId = (string) str()->uuid();

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<epcis:EPCISDocument
    xmlns:epcis="urn:epcglobal:epcis:xsd:1"
    xmlns:sbdh="http://www.unece.org/cefact/namespaces/StandardBusinessDocumentHeader"
    schemaVersion="1.2"
    creationDate="2026-07-15T23:07:33.411Z">
  <EPCISHeader>
    <sbdh:StandardBusinessDocumentHeader>
      <sbdh:HeaderVersion>1.0</sbdh:HeaderVersion>
      <sbdh:Sender>
        <sbdh:Identifier Authority="GLN">0301160000009</sbdh:Identifier>
      </sbdh:Sender>
      <sbdh:Receiver>
        <sbdh:Identifier Authority="GLN">0860249001509</sbdh:Identifier>
      </sbdh:Receiver>
      <sbdh:DocumentIdentification>
        <sbdh:Standard>EPCglobal</sbdh:Standard>
        <sbdh:TypeVersion>1.0</sbdh:TypeVersion>
        <sbdh:InstanceIdentifier>{$instanceId}</sbdh:InstanceIdentifier>
        <sbdh:Type>Events</sbdh:Type>
        <sbdh:CreationDateAndTime>2026-07-15T23:07:33.411Z</sbdh:CreationDateAndTime>
      </sbdh:DocumentIdentification>
    </sbdh:StandardBusinessDocumentHeader>
  </EPCISHeader>
  <EPCISBody>
    <EventList>
      <AggregationEvent>
        <eventTime>2026-07-06T21:13:18.697Z</eventTime>
        <eventTimeZoneOffset>+00:00</eventTimeZoneOffset>
        <parentID>{$ssccUri}</parentID>
        <childEPCs>
          <epc>{$childUri}</epc>
        </childEPCs>
        <action>ADD</action>
        <bizStep>urn:epcglobal:cbv:bizstep:packing</bizStep>
        <disposition>urn:epcglobal:cbv:disp:in_progress</disposition>
      </AggregationEvent>
    </EventList>
  </EPCISBody>
</epcis:EPCISDocument>
XML;
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

        if ($this->documentIds !== []) {
            EpcisException::query()->whereIn('document_id', $this->documentIds)->delete();
            $eventIds = DB::table('epcis_events')->whereIn('document_id', $this->documentIds)->pluck('id');
            DB::table('event_epcs')->whereIn('event_id', $eventIds)->delete();
            DB::table('epcis_events')->whereIn('document_id', $this->documentIds)->delete();
            DB::table('document_epcs')->whereIn('document_id', $this->documentIds)->delete();
            EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            $this->documentIds = [];
        }

        if ($this->epcUris !== []) {
            $epcIds = Epc::query()->whereIn('epc_uri', $this->epcUris)->pluck('id');
            DB::table('aggregation_links')->whereIn('parent_epc_id', $epcIds)->delete();
            DB::table('aggregation_links')->whereIn('child_epc_id', $epcIds)->delete();
            Epc::query()->whereIn('id', $epcIds)->delete();
            $this->epcUris = [];
        }

        tenancy()->end();
    }
}
