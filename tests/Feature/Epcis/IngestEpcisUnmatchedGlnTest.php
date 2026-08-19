<?php

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Enums\TenantProfile;
use App\Jobs\IngestEpcisXmlJob;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisUnmatchedGln;
use App\Models\Site;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IngestEpcisUnmatchedGlnTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?int $documentId = null;

    private ?int $ownedSiteId = null;

    private ?string $originalTenantGln = null;

    private bool $tenantGlnMutated = false;

    #[Test]
    public function ingest_records_unmatched_sender_gln(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->assertTrue(Schema::hasTable('epcis_unmatched_glns'));
            $this->assertTrue(Schema::hasColumn('epcis_documents', 'sender_gln'));

            $unknownSender = '9998887776665';
            $unknownReceiver = '9998887776658';
            $uuid = (string) str()->uuid();
            $xml = $this->minimalXml($uuid, $unknownSender, $unknownReceiver);

            $tmp = tempnam(sys_get_temp_dir(), 'epcis_unmatched_');
            $this->assertNotFalse($tmp);
            file_put_contents($tmp, $xml);

            $document = app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'unmatched_sender.xml',
            ]);
            $this->documentId = (int) $document->getKey();

            $this->assertSame('validated', $document->status);
            $this->assertSame($unknownSender, $document->sender_gln);
            $this->assertNull($document->trading_partner_id);

            $unmatched = EpcisUnmatchedGln::query()
                ->where('document_id', $document->id)
                ->where('context', 'sender')
                ->where('gln', $unknownSender)
                ->first();

            $this->assertNotNull($unmatched);

            $receiver = EpcisUnmatchedGln::query()
                ->where('document_id', $document->id)
                ->where('context', 'receiver')
                ->where('gln', $unknownReceiver)
                ->first();
            $this->assertNotNull($receiver);

            @unlink($tmp);
        } finally {
            $this->cleanup();
            unset($tenant);
        }
    }

    #[Test]
    public function ingest_does_not_flag_receiver_gln_that_matches_owned_site(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $receiverGln = '1200202228045';
            $this->originalTenantGln = $tenant->gln;
            $this->tenantGlnMutated = true;
            $tenant->forceFill(['gln' => $receiverGln])->save();

            $site = Site::factory()->owned()->create([
                'name' => 'Own warehouse',
                'gln' => $receiverGln,
            ]);
            $this->ownedSiteId = (int) $site->getKey();

            $uuid = (string) str()->uuid();
            $xml = $this->minimalXml($uuid, '9998887776665', $receiverGln);
            $tmp = tempnam(sys_get_temp_dir(), 'epcis_own_gln_');
            $this->assertNotFalse($tmp);
            file_put_contents($tmp, $xml);

            $document = app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'own_receiver.xml',
            ]);
            $this->documentId = (int) $document->getKey();

            $this->assertSame($receiverGln, $document->receiver_gln);
            $this->assertNull(
                EpcisUnmatchedGln::query()
                    ->where('document_id', $document->id)
                    ->where('context', 'receiver')
                    ->where('gln', $receiverGln)
                    ->first(),
            );

            @unlink($tmp);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function ingest_does_not_flag_receiver_gln_that_matches_tenant_gln(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $receiverGln = '0366159000010';
            $this->originalTenantGln = $tenant->gln;
            $this->tenantGlnMutated = true;
            $tenant->forceFill(['gln' => $receiverGln])->save();

            $uuid = (string) str()->uuid();
            $xml = $this->minimalXml($uuid, '9998887776665', $receiverGln);
            $tmp = tempnam(sys_get_temp_dir(), 'epcis_tenant_gln_');
            $this->assertNotFalse($tmp);
            file_put_contents($tmp, $xml);

            $document = app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'tenant_receiver.xml',
            ]);
            $this->documentId = (int) $document->getKey();

            $this->assertNull(
                EpcisUnmatchedGln::query()
                    ->where('document_id', $document->id)
                    ->where('context', 'receiver')
                    ->where('gln', $receiverGln)
                    ->first(),
            );

            @unlink($tmp);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function ingest_job_handles_on_sync_queue(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->assertSame('sync', Queue::getDefaultDriver());

            $uuid = (string) str()->uuid();
            $xml = $this->minimalXml($uuid, '0301160000009', '0096295000009');
            $tmp = tempnam(sys_get_temp_dir(), 'epcis_job_');
            $this->assertNotFalse($tmp);
            file_put_contents($tmp, $xml);

            $document = (new IngestEpcisXmlJob($tenant, $tmp, [
                'direction' => 'inbound',
                'original_filename' => 'job_sync.xml',
            ]))->handle();

            $this->documentId = (int) $document->getKey();

            $this->assertSame('validated', $document->status);
            $this->assertSame(1, (int) $document->event_count);
            $this->assertSame(1, (int) $document->epc_count);

            @unlink($tmp);
        } finally {
            $this->cleanup();
        }
    }

    private function minimalXml(string $uuid, string $senderGln, string $receiverGln): string
    {
        $serial = 'JOB'.substr(str_replace('-', '', $uuid), 0, 12);

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<epcis:EPCISDocument xmlns:epcis="urn:epcglobal:epcis:xsd:1" xmlns:sbdh="http://www.unece.org/cefact/namespaces/StandardBusinessDocumentHeader" xmlns:gs1ushc="http://epcis.gs1us.org/hc/ns" schemaVersion="1.2" creationDate="2026-07-15T20:15:49.056Z">
  <EPCISHeader>
    <sbdh:StandardBusinessDocumentHeader>
      <sbdh:HeaderVersion>1.0</sbdh:HeaderVersion>
      <sbdh:Sender><sbdh:Identifier Authority="GLN">{$senderGln}</sbdh:Identifier></sbdh:Sender>
      <sbdh:Receiver><sbdh:Identifier Authority="GLN">{$receiverGln}</sbdh:Identifier></sbdh:Receiver>
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
          <epc>urn:epc:id:sgtin:030116.0200116.{$serial}</epc>
        </epcList>
        <action>ADD</action>
        <bizStep>urn:epcglobal:cbv:bizstep:commissioning</bizStep>
        <disposition>urn:epcglobal:cbv:disp:active</disposition>
        <readPoint>
          <id>urn:epc:id:sgln:030116.000000.0</id>
        </readPoint>
        <bizLocation>
          <id>urn:epc:id:sgln:030116.000000.0</id>
        </bizLocation>
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

        if ($this->ownedSiteId !== null) {
            Site::query()->whereKey($this->ownedSiteId)->delete();
            $this->ownedSiteId = null;
        }

        if ($this->tenantGlnMutated) {
            $tenant = tenant();
            if ($tenant !== null) {
                $tenant->forceFill(['gln' => $this->originalTenantGln])->save();
            }
            $this->originalTenantGln = null;
            $this->tenantGlnMutated = false;
        }

        foreach (Epc::query()->where('epc_uri', 'like', 'urn:epc:id:sgtin:030116.0200116.JOB%')->get() as $epc) {
            if (! DB::table('event_epcs')->where('epc_id', $epc->id)->exists()) {
                $epc->delete();
            }
        }

        tenancy()->end();
    }
}
