<?php

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\ValidateEpcis12Document;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisException;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Exceptions\ExceptionService;
use App\Support\Epcis\Exceptions\GroupDocumentExceptionSignals;
use App\Support\Epcis\Validation\EpcisValidationFinding;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DocumentExceptionGroupingTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $eventIds = [];

    /** @var list<int> */
    private array $caseIds = [];

    #[Test]
    public function groups_duplicate_item_signals_into_one_row_with_failed_gtins(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = $this->makeDocument(['event_count' => 3, 'epc_count' => 3]);
            $gtinA = '00301161111114';
            $gtinB = '00301162222221';
            $epcA1 = $this->makeEpc($gtinA, '111');
            $epcA2 = $this->makeEpc($gtinA, '222');
            $epcB = $this->makeEpc($gtinB, '333');

            foreach ([$epcA1, $epcA2, $epcB] as $epcId) {
                EpcisException::query()->create([
                    'document_id' => $document->getKey(),
                    'epc_id' => $epcId,
                    'exception_type' => 'INVALID_EPC_URI',
                    'severity' => 'error',
                    'description' => 'Serial failed check digit.',
                    'status' => 'open',
                ]);
            }

            $rows = app(GroupDocumentExceptionSignals::class)->handle($document);

            $this->assertCount(1, $rows);
            $row = $rows->first();
            $this->assertSame('INVALID_EPC_URI', $row->exception_type);
            $this->assertSame('items', $row->scope);
            $this->assertSame([$gtinA, $gtinB], $row->gtins);
            $this->assertSame('2 GTINs', $row->gtin_display);
            $this->assertStringNotContainsString($gtinA, (string) $row->gtin_display);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function file_level_signal_uses_entire_file_scope_and_products_in_file(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = $this->makeDocument(['event_count' => 10, 'epc_count' => 2]);
            $gtinA = '00301163333338';
            $gtinB = '00301164444445';
            $this->attachDocumentEpc($document, $this->makeEpc($gtinA, '444'));
            $this->attachDocumentEpc($document, $this->makeEpc($gtinB, '555'));

            EpcisException::query()->create([
                'document_id' => $document->getKey(),
                'exception_type' => 'INGESTION_PARSE_ERROR',
                'severity' => 'error',
                'description' => 'EPCISHeader is not expected.',
                'status' => 'open',
            ]);

            $rows = app(GroupDocumentExceptionSignals::class)->handle($document);

            $this->assertCount(1, $rows);
            $row = $rows->first();
            $this->assertSame('file', $row->scope);
            $this->assertSame('Entire file', $row->scope_display);
            $this->assertSame([$gtinA, $gtinB], $row->gtins);
            $this->assertSame('2 GTINs', $row->gtin_display);
            $this->assertStringNotContainsString($gtinA, (string) $row->gtin_display);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function count_display_includes_ssccs(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = $this->makeDocument(['event_count' => 1, 'epc_count' => 2]);
            $gtin = '00301165555552';
            $sscc = '003011699999999999';
            $sgtinId = $this->makeEpc($gtin, '777');
            $ssccId = $this->makeSscc($sscc);

            EpcisException::query()->create([
                'document_id' => $document->getKey(),
                'epc_id' => $sgtinId,
                'exception_type' => 'MIXED_PACKAGING_LEVELS',
                'severity' => 'warning',
                'description' => 'Mixed packaging.',
                'status' => 'open',
            ]);
            EpcisException::query()->create([
                'document_id' => $document->getKey(),
                'epc_id' => $ssccId,
                'exception_type' => 'MIXED_PACKAGING_LEVELS',
                'severity' => 'warning',
                'description' => 'Mixed packaging.',
                'status' => 'open',
            ]);

            $row = app(GroupDocumentExceptionSignals::class)->handle($document)->first();

            $this->assertSame('1 GTIN · 1 SSCC', $row->gtin_display);
            $this->assertSame([$gtin], $row->gtins);
            $this->assertSame([$sscc], $row->ssccs);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function opening_grouped_item_case_attaches_all_failed_serials(): void
    {
        $this->initializeDemo2Tenant();
        Notification::fake();

        try {
            $document = $this->makeDocument(['event_count' => 2, 'epc_count' => 4]);
            $ids = [];
            $eventIds = [];

            foreach (['a', 'b'] as $i => $suffix) {
                $sgtinId = $this->makeEpc($suffix === 'a' ? '00301162001165' : '00301162001172', (string) (100 + $i));
                $ssccId = $this->makeSscc('0030116'.str_repeat((string) ($i + 1), 10).($i === 0 ? '6' : '3'));
                $ids[] = $sgtinId;
                $ids[] = $ssccId;

                $eventId = (int) DB::table('epcis_events')->insertGetId([
                    'document_id' => $document->getKey(),
                    'ingest_generation' => 1,
                    'event_id' => (string) str()->uuid(),
                    'event_type' => 'ObjectEvent',
                    'event_time' => now(),
                    'action' => 'OBSERVE',
                    'biz_step' => 'urn:epcglobal:cbv:bizstep:packing',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->eventIds[] = $eventId;
                $eventIds[] = $eventId;

                foreach ([$sgtinId, $ssccId] as $epcId) {
                    DB::table('event_epcs')->insert([
                        'event_id' => $eventId,
                        'epc_id' => $epcId,
                        'role' => 'epcList',
                    ]);
                }

                EpcisException::query()->create([
                    'document_id' => $document->getKey(),
                    'event_id' => $eventId,
                    'exception_type' => 'MIXED_PACKAGING_LEVELS',
                    'severity' => 'warning',
                    'description' => 'ObjectEvent epcList mixes SGTIN and SSCC packaging levels.',
                    'status' => 'open',
                ]);
            }

            $user = User::query()->first() ?? User::factory()->create();
            $case = app(ExceptionService::class)->createFromGroupedSignals(
                $document,
                'MIXED_PACKAGING_LEVELS',
                'open',
                $user,
            );
            $this->caseIds[] = (int) $case->getKey();

            $this->assertSame(4, $case->epcs()->count());
            $this->assertEqualsCanonicalizing($ids, $case->epcs()->pluck('epcs.id')->map(fn ($id) => (int) $id)->all());
            $this->assertSame(
                [$case->getKey(), $case->getKey()],
                EpcisException::query()->whereIn('event_id', $eventIds)->orderBy('id')->pluck('case_id')->map(fn ($id) => (int) $id)->all(),
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function opening_collapsed_item_case_attaches_all_serials_for_signal_gtins(): void
    {
        $this->initializeDemo2Tenant();
        Notification::fake();

        try {
            $document = $this->makeDocument(['event_count' => 2, 'epc_count' => 4]);
            $gtinA = '00301161111114';
            $gtinB = '00301162222221';
            $serialA1 = $this->makeEpc($gtinA, 'aaa');
            $serialA2 = $this->makeEpc($gtinA, 'aab');
            $serialB1 = $this->makeEpc($gtinB, 'bba');
            $serialB2 = $this->makeEpc($gtinB, 'bbb');

            foreach ([$serialA1, $serialA2, $serialB1, $serialB2] as $epcId) {
                $this->attachDocumentEpc($document, $epcId);
            }

            EpcisException::query()->create([
                'document_id' => $document->getKey(),
                'event_id' => null,
                'epc_id' => $serialA1,
                'exception_type' => 'MIXED_PACKAGING_LEVELS',
                'severity' => 'warning',
                'description' => 'Collapsed MIXED finding for GTIN A.',
                'status' => 'open',
            ]);
            EpcisException::query()->create([
                'document_id' => $document->getKey(),
                'event_id' => null,
                'epc_id' => $serialB1,
                'exception_type' => 'MIXED_PACKAGING_LEVELS',
                'severity' => 'warning',
                'description' => 'Collapsed MIXED finding for GTIN B.',
                'status' => 'open',
            ]);

            $user = User::query()->first() ?? User::factory()->create();
            $case = app(ExceptionService::class)->createFromGroupedSignals(
                $document,
                'MIXED_PACKAGING_LEVELS',
                'open',
                $user,
            );
            $this->caseIds[] = (int) $case->getKey();

            $this->assertSame(4, $case->epcs()->count());
            $this->assertEqualsCanonicalizing(
                [$serialA1, $serialA2, $serialB1, $serialB2],
                $case->epcs()->pluck('epcs.id')->map(fn ($id) => (int) $id)->all(),
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function opening_grouped_file_case_attaches_one_rep_per_gtin_and_sscc(): void
    {
        $this->initializeDemo2Tenant();
        Notification::fake();

        try {
            $document = $this->makeDocument(['event_count' => 1, 'epc_count' => 5]);
            $gtinA = '00301163333338';
            $gtinB = '00301164444445';
            $serialA1 = $this->makeEpc($gtinA, '111');
            $serialA2 = $this->makeEpc($gtinA, '222');
            $serialB = $this->makeEpc($gtinB, '333');
            $sscc1 = $this->makeSscc('003011611111111111');
            $sscc2 = $this->makeSscc('003011622222222228');

            foreach ([$serialA1, $serialA2, $serialB, $sscc1, $sscc2] as $epcId) {
                $this->attachDocumentEpc($document, $epcId);
            }

            $signal = EpcisException::query()->create([
                'document_id' => $document->getKey(),
                'exception_type' => 'INGESTION_PARSE_ERROR',
                'severity' => 'error',
                'description' => 'EPCISHeader is not expected.',
                'status' => 'open',
            ]);

            $user = User::query()->first() ?? User::factory()->create();
            $case = app(ExceptionService::class)->createFromGroupedSignals(
                $document,
                'INGESTION_PARSE_ERROR',
                'open',
                $user,
            );
            $this->caseIds[] = (int) $case->getKey();

            $attached = $case->epcs()->pluck('epcs.id')->map(fn ($id) => (int) $id)->all();
            $this->assertCount(4, $attached);
            $this->assertNotSame(5, count($attached));
            $this->assertContains($serialB, $attached);
            $this->assertSame(1, count(array_intersect($attached, [$serialA1, $serialA2])));
            $this->assertContains($sscc1, $attached);
            $this->assertContains($sscc2, $attached);
            $this->assertSame($case->getKey(), (int) $signal->fresh()->case_id);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function persist_findings_writes_one_event_only_signal_per_event(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = $this->makeDocument(['event_count' => 2, 'epc_count' => 0]);
            $eventA = $this->insertBareEvent($document);
            $eventB = $this->insertBareEvent($document);

            $method = new \ReflectionMethod(ValidateEpcis12Document::class, 'persistFindings');
            $method->invoke(app(ValidateEpcis12Document::class), $document, [
                new EpcisValidationFinding('MIXED_PACKAGING_LEVELS', 'warning', 'Mixed A.', $eventA),
                new EpcisValidationFinding('MIXED_PACKAGING_LEVELS', 'warning', 'Mixed B.', $eventB),
            ]);

            $rows = EpcisException::query()
                ->where('document_id', $document->getKey())
                ->where('exception_type', 'MIXED_PACKAGING_LEVELS')
                ->orderBy('id')
                ->get();

            $this->assertCount(2, $rows);
            $this->assertEqualsCanonicalizing([$eventA, $eventB], $rows->pluck('event_id')->map(fn ($id) => (int) $id)->all());
            $this->assertTrue($rows->every(fn (EpcisException $row): bool => $row->epc_id === null));

            $grouped = app(GroupDocumentExceptionSignals::class)->handle($document);
            $this->assertCount(1, $grouped);
            $this->assertSame('items', $grouped->first()->scope);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function opening_event_only_mixed_case_attaches_all_event_epcs(): void
    {
        $this->initializeDemo2Tenant();
        Notification::fake();

        try {
            $document = $this->makeDocument(['event_count' => 2, 'epc_count' => 4]);
            $ids = [];
            foreach (['a', 'b'] as $i => $suffix) {
                $sgtinId = $this->makeEpc($suffix === 'a' ? '00301162001189' : '00301162001196', (string) (200 + $i));
                $ssccId = $this->makeSscc('0030117'.str_repeat((string) ($i + 3), 10).($i === 0 ? '2' : '9'));
                $ids[] = $sgtinId;
                $ids[] = $ssccId;
                $eventId = $this->insertBareEvent($document);
                foreach ([$sgtinId, $ssccId] as $epcId) {
                    DB::table('event_epcs')->insert([
                        'event_id' => $eventId,
                        'epc_id' => $epcId,
                        'role' => 'epcList',
                    ]);
                }
                EpcisException::query()->create([
                    'document_id' => $document->getKey(),
                    'event_id' => $eventId,
                    'epc_id' => null,
                    'exception_type' => 'MIXED_PACKAGING_LEVELS',
                    'severity' => 'warning',
                    'description' => 'ObjectEvent epcList mixes SGTIN and SSCC packaging levels.',
                    'status' => 'open',
                ]);
            }

            $user = User::query()->first() ?? User::factory()->create();
            $case = app(ExceptionService::class)->createFromGroupedSignals(
                $document,
                'MIXED_PACKAGING_LEVELS',
                'open',
                $user,
            );
            $this->caseIds[] = (int) $case->getKey();

            $this->assertEqualsCanonicalizing($ids, $case->epcs()->pluck('epcs.id')->map(fn ($id) => (int) $id)->all());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function opening_aggregation_mmf_case_attaches_child_and_parent_roles(): void
    {
        $this->initializeDemo2Tenant();
        Notification::fake();

        try {
            $document = $this->makeDocument(['event_count' => 1, 'epc_count' => 2]);
            $parentId = $this->makeSscc('003011688888888888');
            $childId = $this->makeEpc('00301167777776', 'pack1');
            $eventId = $this->insertBareEvent($document, 'AggregationEvent');

            DB::table('event_epcs')->insert([
                ['event_id' => $eventId, 'epc_id' => $parentId, 'role' => 'parentID'],
                ['event_id' => $eventId, 'epc_id' => $childId, 'role' => 'childEPC'],
            ]);

            EpcisException::query()->create([
                'document_id' => $document->getKey(),
                'event_id' => $eventId,
                'epc_id' => null,
                'exception_type' => 'MIXED_PACKAGING_LEVELS',
                'severity' => 'error',
                'description' => 'Packing event missing readPoint.',
                'status' => 'open',
            ]);

            $user = User::query()->first() ?? User::factory()->create();
            $case = app(ExceptionService::class)->createFromGroupedSignals(
                $document,
                'MIXED_PACKAGING_LEVELS',
                'open',
                $user,
            );
            $this->caseIds[] = (int) $case->getKey();

            $this->assertEqualsCanonicalizing(
                [$parentId, $childId],
                $case->epcs()->pluck('epcs.id')->map(fn ($id) => (int) $id)->all(),
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function opening_unknown_gtin_case_attaches_only_described_gtins(): void
    {
        $this->initializeDemo2Tenant();
        Notification::fake();

        try {
            $document = $this->makeDocument(['event_count' => 1, 'epc_count' => 5]);
            $unknownA = $this->makeEpc('00301161111114', 'u1');
            $unknownA2 = $this->makeEpc('00301161111114', 'u2');
            $unknownB = $this->makeEpc('00301162222221', 'u3');
            $known = $this->makeEpc('00301163333338', 'k1');
            $sscc = $this->makeSscc('003011611111111111');

            foreach ([$unknownA, $unknownA2, $unknownB, $known, $sscc] as $epcId) {
                $this->attachDocumentEpc($document, $epcId);
            }

            EpcisException::query()->create([
                'document_id' => $document->getKey(),
                'exception_type' => 'UNKNOWN_GTIN',
                'severity' => 'error',
                'description' => 'GTIN not found in product master: 00301161111114; GTIN not found in product master: 00301162222221',
                'status' => 'open',
            ]);

            $user = User::query()->first() ?? User::factory()->create();
            $case = app(ExceptionService::class)->createFromGroupedSignals(
                $document,
                'UNKNOWN_GTIN',
                'open',
                $user,
            );
            $this->caseIds[] = (int) $case->getKey();

            $attached = $case->epcs()->pluck('epcs.id')->map(fn ($id) => (int) $id)->all();
            $this->assertEqualsCanonicalizing([$unknownA, $unknownA2, $unknownB], $attached);
            $this->assertNotContains($known, $attached);
            $this->assertNotContains($sscc, $attached);
        } finally {
            $this->cleanup();
        }
    }

    private function insertBareEvent(EpcisDocument $document, string $eventType = 'ObjectEvent'): int
    {
        $eventId = (int) DB::table('epcis_events')->insertGetId([
            'document_id' => $document->getKey(),
            'ingest_generation' => 1,
            'event_id' => (string) str()->uuid(),
            'event_type' => $eventType,
            'event_time' => now(),
            'action' => 'OBSERVE',
            'biz_step' => 'urn:epcglobal:cbv:bizstep:packing',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->eventIds[] = $eventId;

        return $eventId;
    }

    private function makeDocument(array $attributes): EpcisDocument
    {
        $document = EpcisDocument::query()->create(array_merge([
            'document_uuid' => (string) str()->uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'direction' => 'inbound',
            'format' => 'xml',
            'original_filename' => 'exception-group.xml',
            'file_sha256' => hash('sha256', (string) str()->uuid()),
            'payload_disk' => 'local',
            'payload_path' => 'epcis/inbound/exception-group-'.str()->uuid().'.xml',
            'dscsa_affirm' => false,
            'status' => 'error',
            'event_count' => 0,
            'epc_count' => 0,
            'received_at' => now(),
            'ingest_generation' => 1,
        ], $attributes));

        $this->documentIds[] = (int) $document->id;

        return $document;
    }

    private function makeEpc(string $gtin14, string $serial): int
    {
        $id = (int) DB::table('epcs')->insertGetId([
            'epc_uri' => 'urn:epc:id:sgtin:030116.0'.substr($gtin14, -6).'.'.$serial.random_int(10000, 99999),
            'epc_type' => 'sgtin',
            'company_prefix' => '030116',
            'indicator_digit' => '0',
            'item_reference' => substr($gtin14, -6),
            'gtin14' => $gtin14,
            'serial_number' => $serial,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->epcIds[] = $id;

        return $id;
    }

    private function makeSscc(string $sscc18): int
    {
        $id = (int) DB::table('epcs')->insertGetId([
            'epc_uri' => 'urn:epc:id:sscc:030116.'.substr($sscc18, -11).random_int(10, 99),
            'epc_type' => 'sscc',
            'company_prefix' => '030116',
            'sscc18' => $sscc18,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->epcIds[] = $id;

        return $id;
    }

    private function attachDocumentEpc(EpcisDocument $document, int $epcId): void
    {
        if (! Schema::hasTable('document_epcs')) {
            return;
        }

        DB::table('document_epcs')->insert([
            'document_id' => $document->getKey(),
            'epc_id' => $epcId,
            'ingest_generation' => 1,
        ]);
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

        foreach ($this->documentIds as $id) {
            EpcisException::query()->where('document_id', $id)->update(['case_id' => null]);
            EpcisException::query()->where('document_id', $id)->delete();
            if (Schema::hasTable('document_epcs')) {
                DB::table('document_epcs')->where('document_id', $id)->delete();
            }
        }

        foreach ($this->caseIds as $id) {
            $case = ExceptionCase::query()->find($id);
            if ($case === null) {
                continue;
            }
            $case->activities()->delete();
            $case->epcs()->detach();
            $case->delete();
        }
        $this->caseIds = [];

        foreach ($this->eventIds as $id) {
            DB::table('event_epcs')->where('event_id', $id)->delete();
            DB::table('epcis_events')->where('id', $id)->delete();
        }
        $this->eventIds = [];

        foreach ($this->documentIds as $id) {
            EpcisDocument::query()->whereKey($id)->delete();
        }
        $this->documentIds = [];

        foreach ($this->epcIds as $id) {
            if (! DB::table('event_epcs')->where('epc_id', $id)->exists()) {
                Epc::query()->whereKey($id)->delete();
            }
        }
        $this->epcIds = [];

        tenancy()->end();
    }
}
