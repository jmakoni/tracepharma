<?php

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcIlmd;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Epcis\EpcisException;
use App\Models\Epcis\EventEpcIlmd;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EventEpcIlmdPersistenceTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const EPC_URI = 'urn:epc:id:sgtin:030116.0200116.10000082009999';

    private static bool $demo2TenantReady = false;

    private ?int $documentId = null;

    #[Test]
    public function manufacturing_and_best_before_land_in_event_epc_ilmd(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->assertTrue(Schema::hasTable('event_epc_ilmd'));

            $uuid = (string) str()->uuid();
            $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<epcis:EPCISDocument
    xmlns:epcis="urn:epcglobal:epcis:xsd:1"
    xmlns:sbdh="http://www.unece.org/cefact/namespaces/StandardBusinessDocumentHeader"
    xmlns:cbvmda="urn:epcglobal:cbv:mda"
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
          <epc>urn:epc:id:sgtin:030116.0200116.10000082009999</epc>
        </epcList>
        <action>ADD</action>
        <bizStep>urn:epcglobal:cbv:bizstep:commissioning</bizStep>
        <disposition>urn:epcglobal:cbv:disp:active</disposition>
        <extension>
          <ilmd>
            <cbvmda:lotNumber>MFGLOT1</cbvmda:lotNumber>
            <cbvmda:itemExpirationDate>2029-12-31</cbvmda:itemExpirationDate>
            <cbvmda:manufacturingDate>2026-02-01</cbvmda:manufacturingDate>
            <cbvmda:bestBeforeDate>2029-11-30</cbvmda:bestBeforeDate>
            <cbvmda:sellByDate>2029-10-31</cbvmda:sellByDate>
          </ilmd>
        </extension>
      </ObjectEvent>
    </EventList>
  </EPCISBody>
</epcis:EPCISDocument>
XML;

            $tmp = tempnam(sys_get_temp_dir(), 'epcis_ilmd_');
            $this->assertNotFalse($tmp);
            file_put_contents($tmp, $xml);

            $document = app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'ilmd_manufacturing_best_before.xml',
            ]);
            $this->documentId = (int) $document->getKey();

            $epc = Epc::query()->where('epc_uri', self::EPC_URI)->first();
            $this->assertNotNull($epc);

            $event = EpcisEvent::query()
                ->where('document_id', $document->id)
                ->where('event_type', 'ObjectEvent')
                ->first();
            $this->assertNotNull($event);

            $eventIlmd = EventEpcIlmd::query()
                ->where('event_id', $event->id)
                ->where('epc_id', $epc->id)
                ->first();

            $this->assertNotNull($eventIlmd);
            $this->assertSame('MFGLOT1', $eventIlmd->lot_number);
            $this->assertSame('2029-12-31', $eventIlmd->expiry_date?->format('Y-m-d'));
            $this->assertSame('2026-02-01', $eventIlmd->manufacturing_date?->format('Y-m-d'));
            $this->assertSame('2029-11-30', $eventIlmd->best_before_date?->format('Y-m-d'));
            $this->assertSame(['sellByDate' => '2029-10-31'], $eventIlmd->extra_json);

            $epcIlmd = EpcIlmd::query()->where('epc_id', $epc->id)->first();
            $this->assertNotNull($epcIlmd);
            $this->assertSame('2026-02-01', $epcIlmd->manufacturing_date?->format('Y-m-d'));
            $this->assertSame('2029-11-30', $epcIlmd->best_before_date?->format('Y-m-d'));
            $this->assertSame(['sellByDate' => '2029-10-31'], $epcIlmd->extra_json);

            @unlink($tmp);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function shared_epc_ilmd_keeps_first_lot_expiry_and_soft_signals_conflict(): void
    {
        $this->initializeDemo2Tenant();

        $firstDocumentId = null;
        $secondDocumentId = null;

        try {
            $this->assertTrue(Schema::hasTable('epc_ilmd'));

            $uri = 'urn:epc:id:sgtin:030116.0200116.10000082008888';
            $firstUuid = (string) str()->uuid();
            $secondUuid = (string) str()->uuid();

            $firstXml = $this->commissioningXml($firstUuid, $uri, 'LOT-FIRST', '2029-01-31');
            $secondXml = $this->commissioningXml($secondUuid, $uri, 'LOT-SECOND', '2030-06-30');

            $firstTmp = tempnam(sys_get_temp_dir(), 'epcis_ilmd1_');
            $secondTmp = tempnam(sys_get_temp_dir(), 'epcis_ilmd2_');
            $this->assertNotFalse($firstTmp);
            $this->assertNotFalse($secondTmp);
            file_put_contents($firstTmp, $firstXml);
            file_put_contents($secondTmp, $secondXml);

            $first = app(IngestEpcisXmlDocument::class)->handle($firstTmp, [
                'direction' => 'inbound',
                'original_filename' => 'ilmd_first_wins_a.xml',
            ]);
            $firstDocumentId = (int) $first->getKey();

            $second = app(IngestEpcisXmlDocument::class)->handle($secondTmp, [
                'direction' => 'inbound',
                'original_filename' => 'ilmd_first_wins_b.xml',
            ]);
            $secondDocumentId = (int) $second->getKey();

            $epc = Epc::query()->where('epc_uri', $uri)->first();
            $this->assertNotNull($epc);

            $shared = EpcIlmd::query()->where('epc_id', $epc->id)->first();
            $this->assertNotNull($shared);
            $this->assertSame('LOT-FIRST', $shared->lot_number);
            $this->assertSame('2029-01-31', $shared->expiry_date?->format('Y-m-d'));

            $signal = EpcisException::query()
                ->where('document_id', $second->id)
                ->where('epc_id', $epc->id)
                ->where('exception_type', 'LOT_MISMATCH')
                ->where('status', 'open')
                ->first();

            $this->assertNotNull($signal);
            $this->assertSame('warning', $signal->severity);
            $this->assertStringContainsString('LOT-FIRST', (string) $signal->description);
            $this->assertStringContainsString('LOT-SECOND', (string) $signal->description);

            @unlink($firstTmp);
            @unlink($secondTmp);
        } finally {
            $this->cleanupSharedIlmdDocs(
                [$firstDocumentId, $secondDocumentId],
                'urn:epc:id:sgtin:030116.0200116.10000082008888',
            );
        }
    }

    #[Test]
    public function shared_epc_ilmd_keeps_existing_lot_expiry_when_incoming_blank(): void
    {
        $this->initializeDemo2Tenant();

        $firstDocumentId = null;
        $secondDocumentId = null;
        $uri = 'urn:epc:id:sgtin:030116.0200116.10000082007777';

        try {
            $this->assertTrue(Schema::hasTable('epc_ilmd'));

            $firstUuid = (string) str()->uuid();
            $secondUuid = (string) str()->uuid();

            $firstXml = $this->commissioningXml($firstUuid, $uri, 'LOT-KEEP', '2029-01-31');
            $secondXml = $this->commissioningXml($secondUuid, $uri, '', '');

            $firstTmp = tempnam(sys_get_temp_dir(), 'epcis_ilmd_blank1_');
            $secondTmp = tempnam(sys_get_temp_dir(), 'epcis_ilmd_blank2_');
            $this->assertNotFalse($firstTmp);
            $this->assertNotFalse($secondTmp);
            file_put_contents($firstTmp, $firstXml);
            file_put_contents($secondTmp, $secondXml);

            $first = app(IngestEpcisXmlDocument::class)->handle($firstTmp, [
                'direction' => 'inbound',
                'original_filename' => 'ilmd_blank_incoming_a.xml',
            ]);
            $firstDocumentId = (int) $first->getKey();

            $second = app(IngestEpcisXmlDocument::class)->handle($secondTmp, [
                'direction' => 'inbound',
                'original_filename' => 'ilmd_blank_incoming_b.xml',
            ]);
            $secondDocumentId = (int) $second->getKey();

            $epc = Epc::query()->where('epc_uri', $uri)->first();
            $this->assertNotNull($epc);

            $shared = EpcIlmd::query()->where('epc_id', $epc->id)->first();
            $this->assertNotNull($shared);
            $this->assertSame('LOT-KEEP', $shared->lot_number);
            $this->assertSame('2029-01-31', $shared->expiry_date?->format('Y-m-d'));

            $signal = EpcisException::query()
                ->where('document_id', $second->id)
                ->where('epc_id', $epc->id)
                ->where('exception_type', 'LOT_MISMATCH')
                ->where('status', 'open')
                ->first();
            $this->assertNull($signal, 'Blank incoming is a fill, not a conflict');

            @unlink($firstTmp);
            @unlink($secondTmp);
        } finally {
            $this->cleanupSharedIlmdDocs([$firstDocumentId, $secondDocumentId], $uri);
        }
    }

    #[Test]
    public function shared_epc_ilmd_keeps_extended_fields_on_lot_expiry_conflict(): void
    {
        $this->initializeDemo2Tenant();

        $firstDocumentId = null;
        $secondDocumentId = null;
        $uri = 'urn:epc:id:sgtin:030116.0200116.10000082006666';

        try {
            $this->assertTrue(Schema::hasTable('epc_ilmd'));

            $firstUuid = (string) str()->uuid();
            $secondUuid = (string) str()->uuid();

            $firstXml = $this->commissioningXml(
                $firstUuid,
                $uri,
                'LOT-FIRST',
                '2029-01-31',
                manufacturingDate: '2026-02-01',
                bestBeforeDate: '2029-11-30',
                additionalId: 'ADD-FIRST',
                sellByDate: '2029-10-31',
            );
            $secondXml = $this->commissioningXml(
                $secondUuid,
                $uri,
                'LOT-SECOND',
                '2030-06-30',
                manufacturingDate: '2027-03-15',
                bestBeforeDate: '2030-05-01',
                additionalId: 'ADD-SECOND',
                sellByDate: '2030-04-01',
            );

            $firstTmp = tempnam(sys_get_temp_dir(), 'epcis_ilmd_ext1_');
            $secondTmp = tempnam(sys_get_temp_dir(), 'epcis_ilmd_ext2_');
            $this->assertNotFalse($firstTmp);
            $this->assertNotFalse($secondTmp);
            file_put_contents($firstTmp, $firstXml);
            file_put_contents($secondTmp, $secondXml);

            $first = app(IngestEpcisXmlDocument::class)->handle($firstTmp, [
                'direction' => 'inbound',
                'original_filename' => 'ilmd_conflict_extended_a.xml',
            ]);
            $firstDocumentId = (int) $first->getKey();

            $second = app(IngestEpcisXmlDocument::class)->handle($secondTmp, [
                'direction' => 'inbound',
                'original_filename' => 'ilmd_conflict_extended_b.xml',
            ]);
            $secondDocumentId = (int) $second->getKey();

            $epc = Epc::query()->where('epc_uri', $uri)->first();
            $this->assertNotNull($epc);

            $shared = EpcIlmd::query()->where('epc_id', $epc->id)->first();
            $this->assertNotNull($shared);
            $this->assertSame('LOT-FIRST', $shared->lot_number);
            $this->assertSame('2029-01-31', $shared->expiry_date?->format('Y-m-d'));
            $this->assertSame('2026-02-01', $shared->manufacturing_date?->format('Y-m-d'));
            $this->assertSame('2029-11-30', $shared->best_before_date?->format('Y-m-d'));
            $this->assertSame('ADD-FIRST', $shared->additional_id);
            $this->assertSame(['sellByDate' => '2029-10-31'], $shared->extra_json);

            $secondEvent = EpcisEvent::query()
                ->where('document_id', $second->id)
                ->where('event_type', 'ObjectEvent')
                ->first();
            $this->assertNotNull($secondEvent);

            $eventIlmd = EventEpcIlmd::query()
                ->where('event_id', $secondEvent->id)
                ->where('epc_id', $epc->id)
                ->first();
            $this->assertNotNull($eventIlmd);
            $this->assertSame('LOT-SECOND', $eventIlmd->lot_number);
            $this->assertSame('2030-06-30', $eventIlmd->expiry_date?->format('Y-m-d'));
            $this->assertSame('2027-03-15', $eventIlmd->manufacturing_date?->format('Y-m-d'));
            $this->assertSame('2030-05-01', $eventIlmd->best_before_date?->format('Y-m-d'));
            $this->assertSame('ADD-SECOND', $eventIlmd->additional_id);
            $this->assertSame(['sellByDate' => '2030-04-01'], $eventIlmd->extra_json);

            @unlink($firstTmp);
            @unlink($secondTmp);
        } finally {
            $this->cleanupSharedIlmdDocs([$firstDocumentId, $secondDocumentId], $uri);
        }
    }

    /**
     * @param  list<int|null>  $documentIds
     */
    private function cleanupSharedIlmdDocs(array $documentIds, string $epcUri): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        foreach (array_filter($documentIds) as $documentId) {
            EpcisException::query()->where('document_id', $documentId)->delete();
            EpcisDocument::query()->whereKey($documentId)->delete();
        }

        $epc = Epc::query()->where('epc_uri', $epcUri)->first();
        if ($epc !== null && ! DB::table('event_epcs')->where('epc_id', $epc->id)->exists()) {
            EpcIlmd::query()->where('epc_id', $epc->id)->delete();
            $epc->delete();
        }

        tenancy()->end();
    }

    private function commissioningXml(
        string $uuid,
        string $epcUri,
        string $lot,
        string $expiry,
        ?string $manufacturingDate = null,
        ?string $bestBeforeDate = null,
        ?string $additionalId = null,
        ?string $sellByDate = null,
    ): string {
        $extraIlmd = '';
        if ($manufacturingDate !== null && $manufacturingDate !== '') {
            $extraIlmd .= "\n            <cbvmda:manufacturingDate>{$manufacturingDate}</cbvmda:manufacturingDate>";
        }
        if ($bestBeforeDate !== null && $bestBeforeDate !== '') {
            $extraIlmd .= "\n            <cbvmda:bestBeforeDate>{$bestBeforeDate}</cbvmda:bestBeforeDate>";
        }
        if ($additionalId !== null && $additionalId !== '') {
            $extraIlmd .= "\n            <cbvmda:additionalId>{$additionalId}</cbvmda:additionalId>";
        }
        if ($sellByDate !== null && $sellByDate !== '') {
            $extraIlmd .= "\n            <cbvmda:sellByDate>{$sellByDate}</cbvmda:sellByDate>";
        }

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<epcis:EPCISDocument
    xmlns:epcis="urn:epcglobal:epcis:xsd:1"
    xmlns:sbdh="http://www.unece.org/cefact/namespaces/StandardBusinessDocumentHeader"
    xmlns:cbvmda="urn:epcglobal:cbv:mda"
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
          <epc>{$epcUri}</epc>
        </epcList>
        <action>ADD</action>
        <bizStep>urn:epcglobal:cbv:bizstep:commissioning</bizStep>
        <disposition>urn:epcglobal:cbv:disp:active</disposition>
        <extension>
          <ilmd>
            <cbvmda:lotNumber>{$lot}</cbvmda:lotNumber>
            <cbvmda:itemExpirationDate>{$expiry}</cbvmda:itemExpirationDate>{$extraIlmd}
          </ilmd>
        </extension>
      </ObjectEvent>
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

        if ($this->documentId !== null) {
            EpcisDocument::query()->whereKey($this->documentId)->delete();
            $this->documentId = null;
        }

        $epc = Epc::query()->where('epc_uri', self::EPC_URI)->first();
        if ($epc !== null && ! DB::table('event_epcs')->where('epc_id', $epc->id)->exists()) {
            EpcIlmd::query()->where('epc_id', $epc->id)->delete();
            $epc->delete();
        }

        tenancy()->end();
    }
}
