<?php

namespace Tests\Feature\Quarantine;

use App\Actions\Receiving\OpenReceivingSessionFromDocument;
use App\Enums\ExceptionSeverity;
use App\Enums\ExceptionStatus;
use App\Enums\TenantProfile;
use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcIlmd;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionType;
use App\Models\Product;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Receiving\ReceivingSession;
use App\Models\Tenant;
use App\Services\Exceptions\ExceptionService;
use App\Services\Quarantine\QuarantineService;
use App\Services\Quarantine\SupplierQuarantineTableBuilder;
use Database\Seeders\ExceptionCaseSeeder;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DocumentWideExceptionTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $caseIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $productIds = [];

    #[Test]
    public function document_scoped_supplier_page_shows_banner_and_case_rows(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $suffix = substr((string) str()->uuid(), 0, 8);
            $po = 'PO-DOC-'.$suffix;
            $caseSerial = 'CASE'.$suffix;
            $product = Product::query()->create([
                'gtin' => '50301162001167',
                'name' => 'Doc Scope Product '.$suffix,
                'package_ndc' => '0116-2001-16',
                'is_active' => true,
            ]);
            $this->productIds[] = (int) $product->getKey();

            $document = $this->makeDocument([
                'customer_po' => $po,
                'status' => 'parsed',
                'dscsa_affirm' => true,
            ]);

            $parent = $this->makeEpc('p'.$suffix, $product->getKey(), [
                'gtin14' => '50301162001167',
                'serial_number' => $caseSerial,
                'indicator_digit' => 5,
            ]);
            $childA = $this->makeEpc($suffix.'a', $product->getKey());
            $childB = $this->makeEpc($suffix.'b', $product->getKey());
            $this->attachDocumentEpcs($document, [$parent->id, $childA->id, $childB->id]);
            $this->attachCaseAggregation($document, $parent, [$childA, $childB], lot: 'LOT'.$suffix, exp: '2028-06-30');

            $case = $this->makeDocumentScopedCase($document, 'Missing TS fixture '.$suffix);

            $builder = app(SupplierQuarantineTableBuilder::class);
            $this->assertTrue($builder->isDocumentScoped($case->fresh(['epcs', 'quarantineHolds'])));

            $rows = $builder->identifierRows($case->fresh());
            $this->assertCount(1, $rows);
            $this->assertSame($po, $rows->first()['po']);
            $this->assertSame(2, $rows->first()['quantity']);
            $this->assertSame('Doc Scope Product '.$suffix, $rows->first()['product_name']);
            $this->assertSame('0116-2001-16', $rows->first()['ndc']);
            $this->assertSame('50301162001167', $rows->first()['gtin']);
            $this->assertSame($caseSerial, $rows->first()['serial']);
            $this->assertSame('LOT'.$suffix, $rows->first()['lot']);
            $this->assertSame('2028-06-30', $rows->first()['exp']);

            URL::forceRootUrl('http://'.self::DEMO2_DOMAIN);
            $url = app(QuarantineService::class)->signedSupplierUrl($case->fresh());

            tenancy()->end();

            $response = $this->get($url);
            $response->assertOk();
            $response->assertSee('Entire shipment file is affected', false);
            $response->assertSee('cannot be received', false);
            $response->assertSee($po, false);
            $response->assertSee($caseSerial, false);
            $response->assertSee('LOT'.$suffix, false);
            $response->assertSee('Doc Scope Product '.$suffix, false);
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function open_document_scoped_case_blocks_receiving_until_resolved(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $suffix = substr((string) str()->uuid(), 0, 8);
            $document = $this->makeDocument([
                'status' => 'validated',
                'dscsa_affirm' => true,
            ]);
            $document->forceFill(['status' => 'validated'])->save();
            $this->assertSame('validated', $document->fresh()->status);
            $case = $this->makeDocumentScopedCase($document, 'Block receive '.$suffix);

            config([
                'tracepharma.epcis.enforce_ts_for_receiving' => false,
                'tracepharma.epcis.require_validated_for_receiving' => true,
            ]);

            try {
                app(OpenReceivingSessionFromDocument::class)->handle($document);
                $this->fail('Expected DomainException for document-wide block');
            } catch (DomainException $e) {
                $this->assertStringContainsString('document-wide exception #'.$case->getKey(), $e->getMessage());
            }

            $case->forceFill(['status' => ExceptionStatus::Resolved->value])->save();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document->fresh());
            $this->assertSame('open', $session->status);
            $this->assertSame((int) $document->getKey(), (int) $session->epcis_document_id);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function serial_scoped_case_does_not_block_document_receiving(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $suffix = substr((string) str()->uuid(), 0, 8);
            $document = $this->makeDocument([
                'status' => 'validated',
                'dscsa_affirm' => true,
            ]);
            $document->forceFill(['status' => 'validated'])->save();
            $epc = $this->makeEpc($suffix, null);
            $this->attachDocumentEpcs($document, [$epc->id]);

            $case = app(QuarantineService::class)->quarantineFromFindRecall(
                epcIds: [$epc->id],
                reason: 'Serial scoped '.$suffix,
                document: $document,
            );
            $this->caseIds[] = (int) $case->getKey();

            $this->assertFalse($case->fresh(['epcs', 'quarantineHolds'])->isDocumentScoped());

            config(['tracepharma.epcis.enforce_ts_for_receiving' => false]);

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document->fresh());
            $this->assertSame('open', $session->status);
        } finally {
            $this->cleanup();
        }
    }

    private function makeDocumentScopedCase(EpcisDocument $document, string $title): ExceptionCase
    {
        $type = ExceptionType::query()
            ->where('code', 'MISSING_DSCSA_STATEMENT')
            ->where('is_active', true)
            ->first()
            ?? ExceptionType::query()->where('is_active', true)->firstOrFail();

        $case = app(ExceptionService::class)->create([
            'exception_type_id' => $type->getKey(),
            'document_id' => $document->getKey(),
            'trading_partner_id' => $document->trading_partner_id,
            'title' => $title,
            'description' => $title,
            'severity' => ExceptionSeverity::High->value,
            'status' => ExceptionStatus::New->value,
        ]);
        $this->caseIds[] = (int) $case->getKey();

        return $case;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeDocument(array $attributes): EpcisDocument
    {
        $document = EpcisDocument::query()->create(array_merge([
            'document_uuid' => (string) str()->uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'direction' => 'inbound',
            'format' => 'xml',
            'original_filename' => 'doc-wide-test.xml',
            'file_sha256' => hash('sha256', (string) str()->uuid()),
            'payload_disk' => 'local',
            'payload_path' => 'epcis/inbound/doc-wide-'.str()->uuid().'.xml',
            'dscsa_affirm' => true,
            'status' => 'parsed',
            'event_count' => 0,
            'epc_count' => 0,
            'received_at' => now(),
            'processed_at' => now(),
            'ingest_generation' => 1,
        ], $attributes));

        $this->documentIds[] = (int) $document->id;

        return $document;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeEpc(string $suffix, int|string|null $productId, array $overrides = []): Epc
    {
        $epc = Epc::query()->create(array_merge([
            'epc_type' => 'sgtin',
            'epc_uri' => "urn:epc:id:sgtin:030116.0200116.d{$suffix}",
            'gtin14' => '00301162001162',
            'serial_number' => "d{$suffix}",
            'company_prefix' => '030116',
            'indicator_digit' => 0,
            'item_reference' => '200116',
            'product_id' => $productId,
            'first_seen_at' => now(),
        ], $overrides));
        $this->epcIds[] = (int) $epc->id;

        return $epc;
    }

    /**
     * @param  list<Epc>  $children
     */
    private function attachCaseAggregation(
        EpcisDocument $document,
        Epc $parent,
        array $children,
        string $lot,
        string $exp,
    ): void {
        $event = EpcisEvent::query()->create([
            'document_id' => $document->getKey(),
            'event_type' => 'AggregationEvent',
            'event_time' => now(),
            'action' => 'ADD',
            'ingest_generation' => (int) ($document->ingest_generation ?? 1),
        ]);

        EpcIlmd::query()->create([
            'epc_id' => $parent->id,
            'gtin14' => $parent->gtin14,
            'lot_number' => $lot,
            'expiry_date' => $exp,
        ]);

        foreach ($children as $child) {
            AggregationLink::query()->create([
                'parent_epc_id' => $parent->id,
                'child_epc_id' => $child->id,
                'established_by_event_id' => $event->id,
                'link_type' => 'contains',
                'valid_from' => now(),
                'valid_to' => null,
            ]);
        }
    }

    /**
     * @param  list<int>  $epcIds
     */
    private function attachDocumentEpcs(EpcisDocument $document, array $epcIds): void
    {
        $this->assertTrue(Schema::hasTable('document_epcs'));

        foreach ($epcIds as $epcId) {
            DB::table('document_epcs')->insert([
                'document_id' => $document->getKey(),
                'epc_id' => $epcId,
                'ingest_generation' => (int) ($document->ingest_generation ?? 1),
            ]);
        }

        $document->forceFill(['epc_count' => count($epcIds)])->save();
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
        $this->seed(ExceptionCaseSeeder::class);

        return $tenant;
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        foreach ($this->caseIds as $caseId) {
            $case = ExceptionCase::query()->find($caseId);
            if ($case === null) {
                continue;
            }
            $case->activities()->delete();
            QuarantineHold::query()->where('exception_id', $caseId)->delete();
            $case->epcs()->detach();
            $case->delete();
        }
        $this->caseIds = [];

        foreach ($this->epcIds as $id) {
            QuarantineHold::query()->where('epc_id', $id)->delete();
            AggregationLink::query()
                ->where(function ($q) use ($id): void {
                    $q->where('parent_epc_id', $id)->orWhere('child_epc_id', $id);
                })
                ->delete();
            EpcIlmd::query()->where('epc_id', $id)->delete();
            if (Schema::hasTable('document_epcs')) {
                DB::table('document_epcs')->where('epc_id', $id)->delete();
            }
        }

        foreach ($this->documentIds as $id) {
            ReceivingSession::query()->where('epcis_document_id', $id)->delete();
            EpcisEvent::query()->where('document_id', $id)->delete();
            if (Schema::hasTable('document_epcs')) {
                DB::table('document_epcs')->where('document_id', $id)->delete();
            }
            EpcisDocument::query()->whereKey($id)->delete();
        }
        $this->documentIds = [];

        foreach ($this->epcIds as $id) {
            Epc::query()->whereKey($id)->delete();
        }
        $this->epcIds = [];

        foreach ($this->productIds as $id) {
            Product::query()->whereKey($id)->delete();
        }
        $this->productIds = [];

        tenancy()->end();
    }
}
