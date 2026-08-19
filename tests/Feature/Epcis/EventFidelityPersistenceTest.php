<?php

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Epcis\EventQuantity;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EventFidelityPersistenceTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const EPC_URI = 'urn:epc:id:sgtin:030116.0200116.10000082008888';

    private static bool $demo2TenantReady = false;

    private ?int $documentId = null;

    #[Test]
    public function extension_json_and_class_quantities_are_persisted(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->assertTrue(Schema::hasColumn('epcis_events', 'extension_json'));
            $this->assertTrue(Schema::hasTable('event_quantities'));

            $uuid = (string) str()->uuid();
            $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<epcis:EPCISDocument
    xmlns:epcis="urn:epcglobal:epcis:xsd:1"
    xmlns:sbdh="http://www.unece.org/cefact/namespaces/StandardBusinessDocumentHeader"
    xmlns:gs1ushc="http://epcis.gs1us.org/hc/ns"
    schemaVersion="1.2"
    creationDate="2026-07-15T20:15:49.056Z">
  <EPCISHeader>
    <sbdh:StandardBusinessDocumentHeader>
      <sbdh:HeaderVersion>1.0</sbdh:HeaderVersion>
      <sbdh:Sender>
        <sbdh:Identifier Authority="GLN">0301160000009</sbdh:Identifier>
      </sbdh:Sender>
      <sbdh:Receiver>
        <sbdh:Identifier Authority="GLN">0096295000009</sbdh:Identifier>
      </sbdh:Receiver>
      <sbdh:DocumentIdentification>
        <sbdh:Standard>EPCglobal</sbdh:Standard>
        <sbdh:TypeVersion>1.0</sbdh:TypeVersion>
        <sbdh:InstanceIdentifier>{$uuid}</sbdh:InstanceIdentifier>
        <sbdh:Type>Events</sbdh:Type>
        <sbdh:CreationDateAndTime>2026-07-15T20:15:49.056Z</sbdh:CreationDateAndTime>
      </sbdh:DocumentIdentification>
    </sbdh:StandardBusinessDocumentHeader>
    <gs1ushc:dscsaTransactionStatement>
      <gs1ushc:affirmTransactionStatement>true</gs1ushc:affirmTransactionStatement>
      <gs1ushc:legalNotice>Seller has complied with each applicable subsection of FDCA Sec. 581(27)(A)-(G).</gs1ushc:legalNotice>
    </gs1ushc:dscsaTransactionStatement>
  </EPCISHeader>
  <EPCISBody>
    <EventList>
      <ObjectEvent>
        <eventTime>2026-06-18T23:27:32.897Z</eventTime>
        <eventTimeZoneOffset>-05:00</eventTimeZoneOffset>
        <epcList>
          <epc>urn:epc:id:sgtin:030116.0200116.10000082008888</epc>
        </epcList>
        <action>OBSERVE</action>
        <bizStep>urn:epcglobal:cbv:bizstep:shipping</bizStep>
        <quantityList>
          <quantityElement>
            <epcClass>urn:epc:idpat:sgtin:0614141.107346.*</epcClass>
            <quantity>12</quantity>
            <uom>EA</uom>
          </quantityElement>
        </quantityList>
        <extension>
          <customVendorFlag>persist-me</customVendorFlag>
        </extension>
      </ObjectEvent>
      <TransformationEvent>
        <eventTime>2026-07-01T12:00:00.000Z</eventTime>
        <eventTimeZoneOffset>+00:00</eventTimeZoneOffset>
        <transformationID>urn:uuid:transf-persist-0001</transformationID>
        <inputEPCList>
          <epc>urn:epc:id:sgtin:030116.0200116.10000082008888</epc>
        </inputEPCList>
        <outputEPCList>
          <epc>urn:epc:id:sgtin:030116.0200116.10000082008888</epc>
        </outputEPCList>
      </TransformationEvent>
    </EventList>
  </EPCISBody>
</epcis:EPCISDocument>
XML;

            $tmp = tempnam(sys_get_temp_dir(), 'epcis_fidelity_');
            $this->assertNotFalse($tmp);
            file_put_contents($tmp, $xml);

            $document = app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'event_fidelity_extension_quantity.xml',
            ]);
            $this->documentId = (int) $document->getKey();

            $objectEvent = EpcisEvent::query()
                ->where('document_id', $document->id)
                ->where('event_type', 'ObjectEvent')
                ->first();
            $this->assertNotNull($objectEvent);
            $this->assertSame('persist-me', $objectEvent->extension_json['customVendorFlag'] ?? null);

            $qty = EventQuantity::query()
                ->where('event_id', $objectEvent->id)
                ->where('epc_class', 'urn:epc:idpat:sgtin:0614141.107346.*')
                ->first();
            $this->assertNotNull($qty);
            $this->assertSame('quantityList', $qty->role);
            $this->assertSame('12.0000', (string) $qty->quantity);
            $this->assertSame('EA', $qty->uom);

            $transformation = EpcisEvent::query()
                ->where('document_id', $document->id)
                ->where('event_type', 'TransformationEvent')
                ->first();
            $this->assertNotNull($transformation);
            $this->assertSame(
                'urn:uuid:transf-persist-0001',
                $transformation->extension_json['transformation_id'] ?? null,
            );

            @unlink($tmp);
        } finally {
            $this->cleanup();
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

        $epc = Epc::query()->where('epc_uri', self::EPC_URI)->first();
        if ($epc !== null && ! DB::table('event_epcs')->where('epc_id', $epc->id)->exists()) {
            $epc->delete();
        }

        tenancy()->end();
    }
}
