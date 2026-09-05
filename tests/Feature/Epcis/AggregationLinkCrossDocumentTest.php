<?php

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Epcis\ReprocessEpcisDocument;
use App\Enums\TenantProfile;
use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Tenant;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AggregationLinkCrossDocumentTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const SGTIN_URI = 'urn:epc:id:sgtin:030116.0200116.10000082001560';

    private const SSCC_URI = 'urn:epc:id:sscc:030116.01001227052';

    private const SSCC_B_URI = 'urn:epc:id:sscc:030116.01001227099';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $documentIds = [];

    #[Test]
    public function delete_in_document_b_closes_open_link_established_by_document_a(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->assertTrue(Schema::hasTable('aggregation_links'));
            $this->assertTrue(Schema::hasColumn('aggregation_links', 'valid_to'));

            $docA = $this->ingestUniqueFixture('tests/Fixtures/epcis/minimal_object_shipping.xml');
            $this->documentIds[] = (int) $docA->getKey();

            $this->assertSame('validated', $docA->status);

            $parent = Epc::query()->where('epc_uri', self::SSCC_URI)->firstOrFail();
            $child = Epc::query()->where('epc_uri', self::SGTIN_URI)->firstOrFail();

            $openFromA = AggregationLink::query()
                ->where('parent_epc_id', $parent->getKey())
                ->where('child_epc_id', $child->getKey())
                ->whereNull('valid_to')
                ->first();
            $this->assertNotNull($openFromA, 'Document A packing must establish an open aggregation link');

            $docAEventIds = EpcisEvent::query()
                ->where('document_id', $docA->getKey())
                ->pluck('id');
            $this->assertTrue(
                $docAEventIds->contains((int) $openFromA->established_by_event_id),
                'Open link must be established by document A',
            );

            $deleteXml = $this->aggregationDeleteDocumentXml(
                self::SSCC_URI,
                self::SGTIN_URI,
                eventTime: '2026-07-16T10:00:00.000Z',
            );
            $docB = $this->ingestXmlString($deleteXml, 'aggregation_delete_cross_doc.xml');
            $this->documentIds[] = (int) $docB->getKey();

            $this->assertSame('validated', $docB->status);

            $openFromA->refresh();
            $this->assertNotNull(
                $openFromA->valid_to,
                'DELETE in document B must close the open link established by document A',
            );

            $this->assertFalse(
                AggregationLink::query()
                    ->where('parent_epc_id', $parent->getKey())
                    ->where('child_epc_id', $child->getKey())
                    ->whereNull('valid_to')
                    ->exists(),
                'No open (parent, child) link should remain after cross-document DELETE',
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function add_under_parent_b_closes_open_link_under_parent_a(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->assertTrue(Schema::hasTable('aggregation_links'));

            $docA = $this->ingestUniqueFixture('tests/Fixtures/epcis/minimal_object_shipping.xml');
            $this->documentIds[] = (int) $docA->getKey();

            $parentA = Epc::query()->where('epc_uri', self::SSCC_URI)->firstOrFail();
            $child = Epc::query()->where('epc_uri', self::SGTIN_URI)->firstOrFail();

            $linkUnderA = AggregationLink::query()
                ->where('parent_epc_id', $parentA->getKey())
                ->where('child_epc_id', $child->getKey())
                ->whereNull('valid_to')
                ->firstOrFail();

            $repackXml = $this->aggregationAddDocumentXml(
                self::SSCC_B_URI,
                self::SGTIN_URI,
                eventTime: '2026-07-17T12:00:00.000Z',
            );
            // Outbound: TraceLink reserved-check only applies to inbound; this reparent
            // ADD packs an already-commissioned child under a newly commissioned parent.
            $docB = $this->ingestXmlString($repackXml, 'aggregation_add_reparent.xml', 'outbound');
            $this->documentIds[] = (int) $docB->getKey();

            $this->assertSame(
                'validated',
                $docB->status,
                (string) ($docB->error_message ?? 'no error_message'),
            );

            $linkUnderA->refresh();
            $this->assertNotNull(
                $linkUnderA->valid_to,
                'ADD under parent B must close the prior open link under parent A',
            );

            $parentB = Epc::query()->where('epc_uri', self::SSCC_B_URI)->firstOrFail();

            $openUnderB = AggregationLink::query()
                ->where('parent_epc_id', $parentB->getKey())
                ->where('child_epc_id', $child->getKey())
                ->whereNull('valid_to')
                ->first();
            $this->assertNotNull($openUnderB, 'New open link under parent B must exist');

            $this->assertSame(
                1,
                AggregationLink::query()
                    ->where('child_epc_id', $child->getKey())
                    ->whereNull('valid_to')
                    ->count(),
                'Child must have exactly one open parent after reparent ADD',
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function backdated_add_fails_closed_when_newer_open_link_exists(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $docA = $this->ingestUniqueFixture('tests/Fixtures/epcis/minimal_object_shipping.xml');
            $this->documentIds[] = (int) $docA->getKey();

            $parentA = Epc::query()->where('epc_uri', self::SSCC_URI)->firstOrFail();
            $child = Epc::query()->where('epc_uri', self::SGTIN_URI)->firstOrFail();

            $linkUnderA = AggregationLink::query()
                ->where('parent_epc_id', $parentA->getKey())
                ->where('child_epc_id', $child->getKey())
                ->whereNull('valid_to')
                ->firstOrFail();

            // Document A's link opened at 2026-07-15T19:24:20Z. This ADD is backdated
            // to before that, so it must fail closed rather than create a second open parent.
            $backdatedXml = $this->aggregationAddDocumentXml(
                self::SSCC_B_URI,
                self::SGTIN_URI,
                eventTime: '2026-07-01T08:00:00.000Z',
            );

            try {
                $docC = $this->ingestXmlString($backdatedXml, 'aggregation_add_backdated.xml', 'outbound');
                $this->documentIds[] = (int) $docC->getKey();
                $this->fail('Expected DomainException for backdated ADD with newer open link');
            } catch (DomainException $e) {
                $this->assertStringContainsString('newer open link', $e->getMessage());
            }

            $linkUnderA->refresh();
            $this->assertNull(
                $linkUnderA->valid_to,
                'The newer open link must remain open after a rejected backdated ADD',
            );

            $this->assertSame(
                1,
                AggregationLink::query()
                    ->where('child_epc_id', $child->getKey())
                    ->whereNull('valid_to')
                    ->count(),
                'Child must have exactly one open parent; backdated ADD must not insert a second',
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function same_document_reprocess_allows_earlier_add_than_prior_generation_open_links(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $doc = $this->ingestUniqueFixture('tests/Fixtures/epcis/minimal_object_shipping.xml');
            $this->documentIds[] = (int) $doc->getKey();
            $this->assertSame('validated', $doc->status);

            $child = Epc::query()->where('epc_uri', self::SGTIN_URI)->firstOrFail();
            $priorLink = AggregationLink::query()
                ->where('child_epc_id', $child->getKey())
                ->whereNull('valid_to')
                ->firstOrFail();

            $earlierXml = $this->aggregationAddDocumentXml(
                self::SSCC_B_URI,
                self::SGTIN_URI,
                eventTime: '2026-07-01T08:00:00.000Z',
            );

            $tmp = tempnam(sys_get_temp_dir(), 'epcis_corr_');
            $this->assertNotFalse($tmp);
            $path = $tmp.'.xml';
            rename($tmp, $path);
            file_put_contents($path, $earlierXml);

            $disk = (string) ($doc->payload_disk ?: 'local');
            $newPayload = 'epcis/inbound/'.(string) str()->uuid().'.xml';
            Storage::disk($disk)->put($newPayload, file_get_contents($path));

            $doc->forceFill([
                'payload_path' => $newPayload,
                'status' => 'error',
                'error_message' => 'simulated partner correction',
                'file_sha256' => hash_file('sha256', $path),
                'original_filename' => 'corrected-earlier-pack.xml',
            ])->save();

            @unlink($path);

            $reprocessed = app(ReprocessEpcisDocument::class)->handle(
                $doc->fresh(),
                sync: true,
                force: true,
                authorizeExceptionsRole: false,
            );

            $this->assertStringNotContainsString(
                'newer open link',
                (string) $reprocessed->error_message,
                'Same-document corrected reprocess must not be blocked by prior-generation open links (status='
                .$reprocessed->status.' err='.(string) $reprocessed->error_message.')',
            );
            $this->assertFalse(
                str_starts_with((string) $reprocessed->error_message, 'Aggregation ADD at'),
                'Aggregation ADD must project during same-document corrected reprocess (status='
                .$reprocessed->status.' err='.(string) $reprocessed->error_message.')',
            );
        } finally {
            $this->cleanup();
        }
    }

    /**
     * Finding 4 — Aggregation ADD must lock child EPC rows before close+open so
     * concurrent ADDs cannot leave two open parents.
     */
    #[Test]
    public function aggregation_add_locks_child_epc_for_update(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $docA = $this->ingestUniqueFixture('tests/Fixtures/epcis/minimal_object_shipping.xml');
            $this->documentIds[] = (int) $docA->getKey();

            $child = Epc::query()->where('epc_uri', self::SGTIN_URI)->firstOrFail();
            $childId = (int) $child->getKey();

            $sawChildLock = false;
            DB::listen(function ($query) use (&$sawChildLock, $childId): void {
                $sql = strtolower($query->sql);
                if (! str_contains($sql, 'for update')) {
                    return;
                }

                $bindsChild = in_array($childId, $query->bindings, true)
                    || in_array((string) $childId, $query->bindings, false);

                if ($bindsChild && (str_contains($sql, '`epcs`') || str_contains($sql, 'epcs')
                    || str_contains($sql, 'aggregation_links'))) {
                    $sawChildLock = true;
                }
            });

            $repackXml = $this->aggregationAddDocumentXml(
                self::SSCC_B_URI,
                self::SGTIN_URI,
                eventTime: '2026-07-17T12:00:00.000Z',
            );
            $docB = $this->ingestXmlString($repackXml, 'aggregation_add_child_lock.xml', 'outbound');
            $this->documentIds[] = (int) $docB->getKey();

            // Lock is taken during Aggregation ADD projection (persistEvent), before
            // catalog validation. Parent SSCC_B may be uncommissioned → document can
            // land in error; the race serialization still must have locked the child.
            $this->assertTrue(
                $sawChildLock,
                'Expected SELECT ... FOR UPDATE on child EPC (or its aggregation_links) during Aggregation ADD.',
            );
            $this->assertContains(
                $docB->status,
                ['validated', 'error', 'parsed'],
                'Ingest must complete far enough to project Aggregation ADD (status='.$docB->status.', err='.($docB->error_message ?? '').')',
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function delete_with_no_child_epcs_disaggregates_all_open_links_for_parent(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $docA = $this->ingestUniqueFixture('tests/Fixtures/epcis/minimal_object_shipping.xml');
            $this->documentIds[] = (int) $docA->getKey();

            $parent = Epc::query()->where('epc_uri', self::SSCC_URI)->firstOrFail();
            $child = Epc::query()->where('epc_uri', self::SGTIN_URI)->firstOrFail();

            $openFromA = AggregationLink::query()
                ->where('parent_epc_id', $parent->getKey())
                ->where('child_epc_id', $child->getKey())
                ->whereNull('valid_to')
                ->firstOrFail();

            $deleteAllXml = $this->aggregationDeleteAllDocumentXml(
                self::SSCC_URI,
                eventTime: '2026-07-16T10:00:00.000Z',
            );
            $docB = $this->ingestXmlString($deleteAllXml, 'aggregation_delete_all.xml');
            $this->documentIds[] = (int) $docB->getKey();

            $this->assertSame('validated', $docB->status);

            $openFromA->refresh();
            $this->assertNotNull(
                $openFromA->valid_to,
                'A DELETE with empty childEPCs must close every open link under that parent',
            );

            $this->assertFalse(
                AggregationLink::query()
                    ->where('parent_epc_id', $parent->getKey())
                    ->whereNull('valid_to')
                    ->exists(),
                'No open link should remain under the parent after disaggregate-all',
            );
        } finally {
            $this->cleanup();
        }
    }

    private function ingestUniqueFixture(string $relativePath): EpcisDocument
    {
        $fixture = base_path($relativePath);
        $this->assertFileExists($fixture);

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_xdoc_');
        $this->assertNotFalse($tmp);
        $xmlPath = $tmp.'.xml';
        rename($tmp, $xmlPath);

        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);
        $uuid = (string) str()->uuid();
        $xml = str_replace('11111111-2222-3333-4444-555555555555', $uuid, $xml);
        file_put_contents($xmlPath, $xml);

        try {
            return app(IngestEpcisXmlDocument::class)->handle($xmlPath, [
                'direction' => 'inbound',
                'original_filename' => basename($relativePath),
            ]);
        } finally {
            @unlink($xmlPath);
        }
    }

    private function ingestXmlString(string $xml, string $filename, string $direction = 'inbound'): EpcisDocument
    {
        $tmp = tempnam(sys_get_temp_dir(), 'epcis_xdoc_');
        $this->assertNotFalse($tmp);
        $xmlPath = $tmp.'.xml';
        rename($tmp, $xmlPath);
        file_put_contents($xmlPath, $xml);

        try {
            return app(IngestEpcisXmlDocument::class)->handle($xmlPath, [
                'direction' => $direction,
                'original_filename' => $filename,
            ]);
        } finally {
            @unlink($xmlPath);
        }
    }

    private function aggregationDeleteDocumentXml(string $parentUri, string $childUri, string $eventTime): string
    {
        $uuid = (string) str()->uuid();
        $parentUri = htmlspecialchars($parentUri, ENT_XML1);
        $childUri = htmlspecialchars($childUri, ENT_XML1);

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<epcis:EPCISDocument
    xmlns:epcis="urn:epcglobal:epcis:xsd:1"
    xmlns:sbdh="http://www.unece.org/cefact/namespaces/StandardBusinessDocumentHeader"
    xmlns:gs1ushc="http://epcis.gs1us.org/hc/ns"
    schemaVersion="1.2"
    creationDate="2026-07-16T10:00:00.000Z">
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
        <sbdh:CreationDateAndTime>2026-07-16T10:00:00.000Z</sbdh:CreationDateAndTime>
      </sbdh:DocumentIdentification>
    </sbdh:StandardBusinessDocumentHeader>
    <gs1ushc:dscsaTransactionStatement>
      <gs1ushc:affirmTransactionStatement>true</gs1ushc:affirmTransactionStatement>
      <gs1ushc:legalNotice>Seller has complied with each applicable subsection of FDCA Sec. 581(27)(A)-(G).</gs1ushc:legalNotice>
    </gs1ushc:dscsaTransactionStatement>
  </EPCISHeader>
  <EPCISBody>
    <EventList>
      <AggregationEvent>
        <eventTime>{$eventTime}</eventTime>
        <eventTimeZoneOffset>-05:00</eventTimeZoneOffset>
        <parentID>{$parentUri}</parentID>
        <childEPCs>
          <epc>{$childUri}</epc>
        </childEPCs>
        <action>DELETE</action>
        <bizStep>urn:epcglobal:cbv:bizstep:unpacking</bizStep>
        <disposition>urn:epcglobal:cbv:disp:in_progress</disposition>
        <readPoint>
          <id>urn:epc:id:sgln:030116.000000.0</id>
        </readPoint>
        <bizLocation>
          <id>urn:epc:id:sgln:030116.000000.0</id>
        </bizLocation>
      </AggregationEvent>
    </EventList>
  </EPCISBody>
</epcis:EPCISDocument>
XML;
    }

    private function aggregationDeleteAllDocumentXml(string $parentUri, string $eventTime): string
    {
        $uuid = (string) str()->uuid();
        $parentUri = htmlspecialchars($parentUri, ENT_XML1);

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<epcis:EPCISDocument
    xmlns:epcis="urn:epcglobal:epcis:xsd:1"
    xmlns:sbdh="http://www.unece.org/cefact/namespaces/StandardBusinessDocumentHeader"
    xmlns:gs1ushc="http://epcis.gs1us.org/hc/ns"
    schemaVersion="1.2"
    creationDate="2026-07-16T10:00:00.000Z">
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
        <sbdh:CreationDateAndTime>2026-07-16T10:00:00.000Z</sbdh:CreationDateAndTime>
      </sbdh:DocumentIdentification>
    </sbdh:StandardBusinessDocumentHeader>
    <gs1ushc:dscsaTransactionStatement>
      <gs1ushc:affirmTransactionStatement>true</gs1ushc:affirmTransactionStatement>
      <gs1ushc:legalNotice>Seller has complied with each applicable subsection of FDCA Sec. 581(27)(A)-(G).</gs1ushc:legalNotice>
    </gs1ushc:dscsaTransactionStatement>
  </EPCISHeader>
  <EPCISBody>
    <EventList>
      <AggregationEvent>
        <eventTime>{$eventTime}</eventTime>
        <eventTimeZoneOffset>-05:00</eventTimeZoneOffset>
        <parentID>{$parentUri}</parentID>
        <childEPCs/>
        <action>DELETE</action>
        <bizStep>urn:epcglobal:cbv:bizstep:unpacking</bizStep>
        <disposition>urn:epcglobal:cbv:disp:in_progress</disposition>
        <readPoint>
          <id>urn:epc:id:sgln:030116.000000.0</id>
        </readPoint>
        <bizLocation>
          <id>urn:epc:id:sgln:030116.000000.0</id>
        </bizLocation>
      </AggregationEvent>
    </EventList>
  </EPCISBody>
</epcis:EPCISDocument>
XML;
    }

    private function aggregationAddDocumentXml(string $parentUri, string $childUri, string $eventTime): string
    {
        $uuid = (string) str()->uuid();
        $parentUri = htmlspecialchars($parentUri, ENT_XML1);
        $childUri = htmlspecialchars($childUri, ENT_XML1);

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<epcis:EPCISDocument
    xmlns:epcis="urn:epcglobal:epcis:xsd:1"
    xmlns:sbdh="http://www.unece.org/cefact/namespaces/StandardBusinessDocumentHeader"
    xmlns:gs1ushc="http://epcis.gs1us.org/hc/ns"
    schemaVersion="1.2"
    creationDate="2026-07-17T12:00:00.000Z">
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
        <sbdh:CreationDateAndTime>2026-07-17T12:00:00.000Z</sbdh:CreationDateAndTime>
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
        <eventTime>{$eventTime}</eventTime>
        <eventTimeZoneOffset>-05:00</eventTimeZoneOffset>
        <epcList>
          <epc>{$parentUri}</epc>
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
      <AggregationEvent>
        <eventTime>{$eventTime}</eventTime>
        <eventTimeZoneOffset>-05:00</eventTimeZoneOffset>
        <parentID>{$parentUri}</parentID>
        <childEPCs>
          <epc>{$childUri}</epc>
        </childEPCs>
        <action>ADD</action>
        <bizStep>urn:epcglobal:cbv:bizstep:packing</bizStep>
        <disposition>urn:epcglobal:cbv:disp:in_progress</disposition>
        <readPoint>
          <id>urn:epc:id:sgln:030116.000000.0</id>
        </readPoint>
        <bizLocation>
          <id>urn:epc:id:sgln:030116.000000.0</id>
        </bizLocation>
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
            ]);
            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant);

        return $tenant;
    }

    private function cleanup(): void
    {
        if ($this->documentIds !== []) {
            $eventIds = EpcisEvent::query()->whereIn('document_id', $this->documentIds)->pluck('id');
            if ($eventIds->isNotEmpty()) {
                DB::table('aggregation_links')->whereIn('established_by_event_id', $eventIds)->delete();
                DB::table('event_epcs')->whereIn('event_id', $eventIds)->delete();
            }
            EpcisEvent::query()->whereIn('document_id', $this->documentIds)->delete();
            DB::table('document_epcs')->whereIn('document_id', $this->documentIds)->delete();
            EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            $this->documentIds = [];
        }

        if (tenancy()->initialized) {
            tenancy()->end();
        }
    }
}
