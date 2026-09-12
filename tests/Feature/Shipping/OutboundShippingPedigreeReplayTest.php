<?php

namespace Tests\Feature\Shipping;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisPedigreeEventFragment;
use App\Models\Tenant;
use App\Support\Epcis\ExtractPriorPedigreeXml;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pedigree replay fidelity without relying on full outbound ship/receive custody setup.
 */
class OutboundShippingPedigreeReplayTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const SSCC_URI = 'urn:epc:id:sscc:030116.01009998877';

    private const SGTIN_URI = 'urn:epc:id:sgtin:030116.0200116.10000099998877';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    #[Test]
    public function extract_prior_pedigree_replays_inbound_commission_time_and_excludes_receiving(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config([
                'tracepharma.epcis.payload_disk' => 'local',
                'filesystems.default' => 'local',
            ]);

            $document = $this->ingestIsolatedFixture();
            $this->documentIds[] = (int) $document->getKey();
            $this->assertSame('validated', $document->status, (string) $document->error_message);

            $sscc = Epc::query()->where('epc_uri', self::SSCC_URI)->firstOrFail();
            $this->epcIds[] = (int) $sscc->getKey();
            $sgtin = Epc::query()->where('epc_uri', self::SGTIN_URI)->first();
            if ($sgtin !== null) {
                $this->epcIds[] = (int) $sgtin->getKey();
            }

            $pedigree = app(ExtractPriorPedigreeXml::class)->forOpenTree([(int) $sscc->getKey()]);

            $this->assertContains((int) $document->getKey(), $pedigree['source_document_ids']);
            $this->assertSame([(int) $document->getKey()], $pedigree['source_document_ids']);
            $this->assertGreaterThan(0, $pedigree['event_count']);
            $this->assertSame('whole_event', config('tracepharma.epcis.outbound_pedigree_replay'));

            $joined = implode("\n", $pedigree['event_xml']);
            $this->assertStringContainsString('2026-06-18T23:27:32.897Z', $joined);
            $this->assertStringContainsString('urn:epc:id:sgln:030116.000000.0', $joined);
            $this->assertStringContainsString('bizstep:commissioning', $joined);
            $this->assertStringContainsString('bizstep:packing', $joined);
            $this->assertStringContainsString(self::SSCC_URI, $joined);
            $this->assertStringNotContainsString('bizstep:receiving', $joined);
            $this->assertStringNotContainsString('urn:epcglobal:cbv:bizstep:receiving', $joined);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function extract_prior_pedigree_replays_from_db_fragments_when_payload_file_is_gone(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config([
                'tracepharma.epcis.payload_disk' => 'local',
                'filesystems.default' => 'local',
            ]);

            $this->assertTrue(
                Schema::hasTable('epcis_pedigree_event_fragments'),
                'Run tenants:migrate so pedigree fragment tables exist',
            );

            $document = $this->ingestIsolatedFixture();
            $this->documentIds[] = (int) $document->getKey();
            $this->assertSame('validated', $document->status, (string) $document->error_message);

            $fragmentCount = EpcisPedigreeEventFragment::query()
                ->where('document_id', $document->getKey())
                ->count();
            $this->assertGreaterThan(0, $fragmentCount, 'Ingest should persist pedigree event fragments');

            $disk = (string) ($document->payload_disk ?: 'local');
            $path = (string) $document->payload_path;
            $this->assertTrue(Storage::disk($disk)->exists($path));
            Storage::disk($disk)->delete($path);
            $this->assertFalse(Storage::disk($disk)->exists($path));

            $sscc = Epc::query()->where('epc_uri', self::SSCC_URI)->firstOrFail();
            $this->epcIds[] = (int) $sscc->getKey();
            $sgtin = Epc::query()->where('epc_uri', self::SGTIN_URI)->first();
            if ($sgtin !== null) {
                $this->epcIds[] = (int) $sgtin->getKey();
            }

            $pedigree = app(ExtractPriorPedigreeXml::class)->forOpenTree([(int) $sscc->getKey()]);

            $joined = implode("\n", $pedigree['event_xml']);
            $this->assertStringContainsString('2026-06-18T23:27:32.897Z', $joined);
            $this->assertStringContainsString('bizstep:commissioning', $joined);
            $this->assertStringContainsString('bizstep:packing', $joined);
            $this->assertStringContainsString(self::SSCC_URI, $joined);
            $this->assertStringNotContainsString('bizstep:receiving', $joined);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function extract_prior_pedigree_filters_packing_child_epcs_to_open_tree(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config([
                'tracepharma.epcis.payload_disk' => 'local',
                'filesystems.default' => 'local',
            ]);

            $document = $this->ingestIsolatedFixture();
            $this->documentIds[] = (int) $document->getKey();
            $this->assertSame('validated', $document->status, (string) $document->error_message);

            $sscc = Epc::query()->where('epc_uri', self::SSCC_URI)->firstOrFail();
            $sgtin = Epc::query()->where('epc_uri', self::SGTIN_URI)->firstOrFail();
            $this->epcIds[] = (int) $sscc->getKey();
            $this->epcIds[] = (int) $sgtin->getKey();

            $extraUri = 'urn:epc:id:sgtin:030116.0200116.10000099998878';
            $packXml = <<<XML
<AggregationEvent>
  <eventTime>2026-07-15T19:24:20.000Z</eventTime>
  <parentID>{$this->escape(self::SSCC_URI)}</parentID>
  <childEPCs>
    <epc>{$this->escape(self::SGTIN_URI)}</epc>
    <epc>{$this->escape($extraUri)}</epc>
  </childEPCs>
  <action>ADD</action>
  <bizStep>urn:epcglobal:cbv:bizstep:packing</bizStep>
</AggregationEvent>
XML;

            // Replace packing fragments so TI packing lists an off-tree child to strip.
            \App\Models\Epcis\EpcisPedigreeEventFragment::query()
                ->where('document_id', $document->getKey())
                ->where('event_local_name', 'AggregationEvent')
                ->delete();

            \App\Models\Epcis\EpcisPedigreeEventFragment::query()->create([
                'document_id' => $document->getKey(),
                'ingest_generation' => (int) ($document->ingest_generation ?? 1),
                'event_local_name' => 'AggregationEvent',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:packing',
                'event_time' => '2026-07-15T19:24:20.000Z',
                'seq' => 0,
                'xml_sha256' => hash('sha256', $packXml),
                'event_xml' => $packXml,
            ]);

            // Force fragment path (delete payload so payload fallback is unused).
            $disk = (string) ($document->payload_disk ?: 'local');
            Storage::disk($disk)->delete((string) $document->payload_path);

            $pedigree = app(ExtractPriorPedigreeXml::class)->forOpenTree([(int) $sscc->getKey()]);
            $joined = implode("\n", $pedigree['event_xml']);
            $this->assertStringContainsString(self::SGTIN_URI, $joined);
            $this->assertStringNotContainsString($extraUri, $joined);
            $this->assertStringContainsString('<childEPCs>', $joined);
        } finally {
            $this->cleanup();
        }
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES);
    }

    #[Test]
    public function shipping_authoring_does_not_call_facility_sgln_rewrite(): void
    {
        $generate = (string) file_get_contents(base_path('app/Actions/Shipping/GenerateShippingEpcisEvents.php'));
        $fullHistory = (string) file_get_contents(base_path('app/Support/Epcis/BuildFullHistoryShippingEpcisXml.php'));

        $this->assertStringNotContainsString('Sgln::toFacilityUrn', $generate);
        $this->assertStringNotContainsString('toFacilityUrn', $fullHistory);
        $this->assertStringNotContainsString('asDscsaFacilitySgln', $fullHistory);
    }

    private function ingestIsolatedFixture(): EpcisDocument
    {
        $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
        $this->assertFileExists($fixture);

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_');
        $this->assertNotFalse($tmp);
        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);
        $xml = str_replace('11111111-2222-3333-4444-555555555555', (string) Str::uuid(), $xml);
        $xml = str_replace('urn:epc:id:sscc:030116.01001227052', self::SSCC_URI, $xml);
        $xml = str_replace('urn:epc:id:sgtin:030116.0200116.10000082001560', self::SGTIN_URI, $xml);
        file_put_contents($tmp, $xml);

        try {
            return app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'pedigree-isolated.xml',
                'payload_disk' => 'local',
            ]);
        } finally {
            @unlink($tmp);
        }
    }

    private function initializeDemo2Tenant(): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo Wholesaler',
                'profile' => TenantProfile::DrugWholesaler,
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

        tenancy()->initialize($tenant->fresh());

        return $tenant;
    }

    private function cleanup(): void
    {
        if ($this->documentIds !== []) {
            $eventIds = DB::table('epcis_events')->whereIn('document_id', $this->documentIds)->pluck('id')->all();
            if ($eventIds !== []) {
                DB::table('event_epcs')->whereIn('event_id', $eventIds)->delete();
                if (DB::getSchemaBuilder()->hasTable('aggregation_links')) {
                    DB::table('aggregation_links')->whereIn('established_by_event_id', $eventIds)->delete();
                }
                DB::table('epcis_events')->whereIn('id', $eventIds)->delete();
            }
            foreach (EpcisDocument::query()->whereIn('id', $this->documentIds)->get() as $doc) {
                if (filled($doc->payload_path)) {
                    try {
                        Storage::disk((string) ($doc->payload_disk ?: 'local'))->delete((string) $doc->payload_path);
                    } catch (\Throwable) {
                    }
                }
            }
            EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            $this->documentIds = [];
        }

        if ($this->epcIds !== []) {
            if (DB::getSchemaBuilder()->hasTable('aggregation_links')) {
                DB::table('aggregation_links')
                    ->where(function ($q): void {
                        $q->whereIn('parent_epc_id', $this->epcIds)
                            ->orWhereIn('child_epc_id', $this->epcIds);
                    })
                    ->delete();
            }
            if (DB::getSchemaBuilder()->hasTable('epc_ilmd')) {
                DB::table('epc_ilmd')->whereIn('epc_id', $this->epcIds)->delete();
            }
            if (DB::getSchemaBuilder()->hasTable('event_epc_ilmd')) {
                DB::table('event_epc_ilmd')->whereIn('epc_id', $this->epcIds)->delete();
            }
            if (DB::getSchemaBuilder()->hasTable('event_epcs')) {
                DB::table('event_epcs')->whereIn('epc_id', $this->epcIds)->delete();
            }
            if (DB::getSchemaBuilder()->hasTable('document_epcs')) {
                DB::table('document_epcs')->whereIn('epc_id', $this->epcIds)->delete();
            }
            if (DB::getSchemaBuilder()->hasTable('exception_epcs')) {
                DB::table('exception_epcs')->whereIn('epc_id', $this->epcIds)->delete();
            }
            if (DB::getSchemaBuilder()->hasTable('epcis_exceptions')) {
                DB::table('epcis_exceptions')->whereIn('epc_id', $this->epcIds)->delete();
            }
            Epc::query()->whereIn('id', $this->epcIds)->delete();
            $this->epcIds = [];
        }

        if (tenancy()->initialized) {
            tenancy()->end();
        }
    }
}
