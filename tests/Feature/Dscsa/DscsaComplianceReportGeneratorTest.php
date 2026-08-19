<?php

namespace Tests\Feature\Dscsa;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Enums\TenantProfile;
use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcIlmd;
use App\Models\Epcis\EpcisDocument;
use App\Models\Receiving\ReceivingSession;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Dscsa\DscsaComplianceReportGenerator;
use App\Services\Dscsa\TransactionReportGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DscsaComplianceReportGeneratorTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const UNIT_URI = 'urn:epc:id:sgtin:030116.0200116.10000082001560';

    private const SSCC_URI = 'urn:epc:id:sscc:030116.01001227052';

    private static bool $demo2TenantReady = false;

    private ?int $documentId = null;

    /** @var list<int> */
    private array $extraEpcIds = [];

    #[Test]
    public function it_lists_unit_serials_excludes_sscc_and_renders_pdf(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $email = 'compliance-'.substr((string) str()->uuid(), 0, 8).'@example.test';
            $user = User::factory()->create([
                'name' => 'Joel Makoni',
                'email' => $email,
            ]);

            $document = $this->ingestFixture('minimal_with_shipping_refs.xml');
            $this->documentId = (int) $document->getKey();

            $generator = app(DscsaComplianceReportGenerator::class);
            $data = $generator->buildData($document, $user);

            $this->assertNotEmpty($data->pages);
            $page = $data->pages[0];
            $this->assertSame('lot_first', $page->kind);
            $this->assertSame('606412T', $page->lot);
            $this->assertSame(1, $page->pageNumber);
            $this->assertGreaterThanOrEqual(1, $page->totalPages);
            $this->assertStringContainsString('581(27)', $page->legalStatement);
            $this->assertSame('Joel Makoni ('.$email.')', $data->footer['generated_by']);
            $this->assertNull($page->ownershipNote);
            $this->assertNotEmpty($page->ownershipRows);

            $serials = $page->serialRows;
            $this->assertNotEmpty($serials);
            $this->assertSame('10000082001560', $serials[0]->serialNumber);
            $this->assertSame('606412T', $serials[0]->lot);

            foreach ($serials as $row) {
                $this->assertStringNotContainsString('sscc', strtolower($row->gtin));
            }

            $sscc = Epc::query()->where('epc_uri', self::SSCC_URI)->first();
            $this->assertNotNull($sscc);
            foreach ($serials as $row) {
                $this->assertNotSame((string) $sscc->serial_number, $row->serialNumber);
            }

            $result = $generator->generate($document, $user);
            $this->assertStringStartsWith('%PDF', $result['binary']);
            $this->assertStringStartsWith('DSCSA_Compliance_Report_', $result['filename']);
            $this->assertStringContainsString('606412T', $result['filename']);
            $this->assertStringEndsWith('.pdf', $result['filename']);

            $user->delete();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function it_includes_case_level_sgtin_with_zero_children_and_excludes_parent_with_children(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = $this->ingestFixture('minimal_object_shipping.xml');
            $this->documentId = (int) $document->getKey();

            $unit = Epc::query()->where('epc_uri', self::UNIT_URI)->firstOrFail();
            $generation = (int) ($document->ingest_generation ?? 1);

            // Case SGTIN with no children — should be listed.
            $leafCase = Epc::query()->create([
                'epc_type' => 'sgtin',
                'epc_uri' => 'urn:epc:id:sgtin:030116.0200116.caseleaf001',
                'gtin14' => '00301162001162',
                'serial_number' => 'caseleaf001',
                'company_prefix' => '030116',
                'first_seen_at' => now(),
            ]);
            $this->extraEpcIds[] = (int) $leafCase->id;
            EpcIlmd::query()->create([
                'epc_id' => $leafCase->id,
                'gtin14' => '00301162001162',
                'lot_number' => '606412T',
                'expiry_date' => '2029-05-31',
            ]);
            DB::table('document_epcs')->insert([
                'document_id' => $document->getKey(),
                'epc_id' => $leafCase->id,
                'ingest_generation' => $generation,
            ]);

            // Parent SGTIN with a child — parent excluded, child included.
            $parentCase = Epc::query()->create([
                'epc_type' => 'sgtin',
                'epc_uri' => 'urn:epc:id:sgtin:030116.0200116.parentcase01',
                'gtin14' => '00301162001162',
                'serial_number' => 'parentcase01',
                'company_prefix' => '030116',
                'first_seen_at' => now(),
            ]);
            $this->extraEpcIds[] = (int) $parentCase->id;
            EpcIlmd::query()->create([
                'epc_id' => $parentCase->id,
                'gtin14' => '00301162001162',
                'lot_number' => '606412T',
                'expiry_date' => '2029-05-31',
            ]);
            DB::table('document_epcs')->insert([
                'document_id' => $document->getKey(),
                'epc_id' => $parentCase->id,
                'ingest_generation' => $generation,
            ]);

            $childUnit = Epc::query()->create([
                'epc_type' => 'sgtin',
                'epc_uri' => 'urn:epc:id:sgtin:030116.0200116.childunit001',
                'gtin14' => '00301162001162',
                'serial_number' => 'childunit001',
                'company_prefix' => '030116',
                'first_seen_at' => now(),
            ]);
            $this->extraEpcIds[] = (int) $childUnit->id;
            EpcIlmd::query()->create([
                'epc_id' => $childUnit->id,
                'gtin14' => '00301162001162',
                'lot_number' => '606412T',
                'expiry_date' => '2029-05-31',
            ]);
            DB::table('document_epcs')->insert([
                'document_id' => $document->getKey(),
                'epc_id' => $childUnit->id,
                'ingest_generation' => $generation,
            ]);

            $eventId = (int) $document->events()->value('id');
            AggregationLink::query()->create([
                'parent_epc_id' => $parentCase->id,
                'child_epc_id' => $childUnit->id,
                'established_by_event_id' => $eventId,
                'link_type' => 'contains',
                'valid_from' => now(),
                'valid_to' => null,
                'created_at' => now(),
            ]);

            $data = app(DscsaComplianceReportGenerator::class)->buildData($document->fresh());
            $serials = collect($data->pages[0]->serialRows)->pluck('serialNumber')->all();

            $this->assertContains('10000082001560', $serials);
            $this->assertContains('caseleaf001', $serials);
            $this->assertContains('childunit001', $serials);
            $this->assertNotContains('parentcase01', $serials);
            $this->assertNotContains((string) $unit->sscc18, $serials);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function transaction_report_still_works_after_shared_context_extract(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = $this->ingestFixture('minimal_object_shipping.xml');
            $this->documentId = (int) $document->getKey();

            $result = app(TransactionReportGenerator::class)->generate($document);
            $this->assertStringStartsWith('%PDF', $result['binary']);
            $this->assertSame('606412T', $result['data']->pages[0]->lot);
        } finally {
            $this->cleanup();
        }
    }

    private function ingestFixture(string $name): EpcisDocument
    {
        $fixture = base_path('tests/Fixtures/epcis/'.$name);
        $this->assertFileExists($fixture);

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_');
        $this->assertNotFalse($tmp);
        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);
        $uuid = (string) str()->uuid();
        $xml = str_replace('11111111-2222-3333-4444-555555555555', $uuid, $xml);
        $xml = str_replace('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $uuid, $xml);
        file_put_contents($tmp, $xml);

        try {
            return app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => $name,
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

        foreach ($this->extraEpcIds as $epcId) {
            AggregationLink::query()->where('parent_epc_id', $epcId)->orWhere('child_epc_id', $epcId)->delete();
            EpcIlmd::query()->where('epc_id', $epcId)->delete();
            DB::table('document_epcs')->where('epc_id', $epcId)->delete();
            Epc::query()->whereKey($epcId)->delete();
        }
        $this->extraEpcIds = [];

        if ($this->documentId !== null) {
            if (Schema::hasTable('receiving_sessions')) {
                ReceivingSession::query()
                    ->where('epcis_document_id', $this->documentId)
                    ->delete();
            }
            EpcisDocument::query()->whereKey($this->documentId)->delete();
            $this->documentId = null;
        }

        tenancy()->end();
    }
}
