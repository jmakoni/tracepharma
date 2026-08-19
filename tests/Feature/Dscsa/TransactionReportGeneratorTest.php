<?php

namespace Tests\Feature\Dscsa;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Enums\TenantProfile;
use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Receiving\ReceivingSession;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Dscsa\TransactionReport\TransactionReportDataBuilder;
use App\Services\Dscsa\TransactionReportGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionReportGeneratorTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?int $documentId = null;

    #[Test]
    public function it_builds_one_page_per_lot_with_footer_and_ownership_note_when_no_shipping(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $email = 'txn-report-'.substr((string) str()->uuid(), 0, 8).'@example.test';
            $user = User::factory()->create([
                'name' => 'Joel Makoni',
                'email' => $email,
            ]);

            $document = $this->ingestFixture('minimal_object_shipping.xml');
            $this->documentId = (int) $document->getKey();

            $generator = app(TransactionReportGenerator::class);
            $data = $generator->buildData($document, $user);

            $this->assertNotEmpty($data->pages);
            $this->assertSame('606412T', $data->pages[0]->lot);
            $this->assertSame(1, $data->pages[0]->numberOfContainers);
            $this->assertSame(1, $data->pages[0]->qty);
            $this->assertStringContainsString('Ownership transfer is not yet present', (string) $data->pages[0]->ownershipNote);
            $this->assertStringContainsString('581(27)', $data->pages[0]->legalStatement);
            $this->assertSame('Joel Makoni ('.$email.')', $data->footer['generated_by']);
            $this->assertNotSame('', $data->footer['generated_from']);
            $this->assertMatchesRegularExpression('/\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}:\d{2}/', $data->footer['generated_at']);

            $result = $generator->generate($document, $user);
            $this->assertStringStartsWith('%PDF', $result['binary']);
            $this->assertStringStartsWith('Transaction_Report_', $result['filename']);
            $this->assertStringEndsWith('.pdf', $result['filename']);

            $user->delete();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function it_counts_aggregation_children_document_scoped_when_parent_is_shared(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = $this->ingestFixture('minimal_object_shipping.xml');
            $this->documentId = (int) $document->getKey();

            $parent = Epc::query()->where('epc_uri', 'urn:epc:id:sscc:030116.01001227052')->firstOrFail();
            $serial = 'txnrpt'.substr((string) str()->uuid(), 0, 12);
            $extraChild = Epc::query()->create(Epc::materializeAttributesFromUri(
                'urn:epc:id:sgtin:030116.0200116.'.$serial,
            ));

            $otherDocument = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'received_at' => now(),
                'direction' => 'inbound',
                'status' => 'validated',
                'original_filename' => 'other-doc-aggregation.xml',
            ]);

            $otherEvent = EpcisEvent::query()->create([
                'document_id' => $otherDocument->getKey(),
                'event_id' => 'urn:uuid:'.(string) str()->uuid(),
                'event_type' => 'AggregationEvent',
                'event_time' => now(),
                'record_time' => now(),
                'event_timezone_offset' => '+00:00',
                'action' => 'ADD',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:packing',
                'disposition' => 'urn:epcglobal:cbv:disp:in_progress',
            ]);

            AggregationLink::query()->create([
                'parent_epc_id' => $parent->getKey(),
                'child_epc_id' => $extraChild->getKey(),
                'established_by_event_id' => $otherEvent->getKey(),
                'link_type' => 'pack',
                'valid_from' => now(),
                'valid_to' => null,
            ]);

            $tenantWideChildCount = (int) DB::table('aggregation_links')
                ->where('parent_epc_id', $parent->getKey())
                ->whereNull('valid_to')
                ->distinct()
                ->count('child_epc_id');
            $this->assertGreaterThan(
                1,
                $tenantWideChildCount,
                'Fixture must include an extra open child under the shared parent outside this document.',
            );

            $data = app(TransactionReportDataBuilder::class)->build($document->fresh());
            $this->assertNotEmpty($data->pages);
            $this->assertSame(1, $data->pages[0]->qty);
            $this->assertLessThan($tenantWideChildCount, $data->pages[0]->qty);

            EpcisDocument::query()->whereKey($otherDocument->getKey())->delete();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function it_includes_ownership_rows_when_shipping_event_present(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = $this->ingestFixture('minimal_with_shipping_refs.xml');
            $this->documentId = (int) $document->getKey();

            $data = app(TransactionReportGenerator::class)->buildData($document);

            $this->assertNotEmpty($data->pages);
            $page = $data->pages[0];
            $this->assertNull($page->ownershipNote);
            $this->assertNotEmpty($page->ownershipRows);
            $this->assertSame(1, $page->ownershipRows[0]['order']);
            $this->assertNotSame('—', $page->transactionDate);
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
