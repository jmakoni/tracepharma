<?php

namespace Tests\Feature\Exceptions;

use App\Enums\EpcisGuideline;
use App\Enums\ExceptionSeverity;
use App\Enums\ExceptionStatus;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionType;
use App\Models\Tenant;
use App\Services\Receiving\ReceivingGate;
use Database\Seeders\ExceptionTypeSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExceptionPolicyReceivingGateTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $caseIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $eventIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    #[Test]
    public function seeder_rank_matches_receive_gate_for_serial_shipped_and_broken_aggregation(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $serial = ExceptionType::query()->where('code', 'SERIAL_SHIPPED_NOT_COMMISSIONED')->firstOrFail();
            $broken = ExceptionType::query()->where('code', 'BROKEN_AGGREGATION')->firstOrFail();

            $this->assertSame(ExceptionSeverity::High, $serial->default_severity);
            $this->assertTrue($serial->receive_impact?->blocksReceiving());
            $this->assertSame(ExceptionSeverity::Medium, $broken->default_severity);
            $this->assertFalse($broken->receive_impact?->blocksReceiving());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function r12_inbound_without_guideline_version_does_not_hard_block_r13_only_codes(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = $this->createDocument(EpcisGuideline::R12);
            $gate = app(ReceivingGate::class);

            foreach (['DROP_SHIPMENT_INDICATOR_MISSING', 'MISSING_PARENT', 'LOT_MISMATCH'] as $code) {
                $case = $this->openDocumentCase($document, $code);
                $this->assertNull(
                    $gate->documentBlockedByOpenException($document->fresh()),
                    $code.' must not HB an R1.2 file without guidelineVersion.',
                );
                $case->delete();
            }
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function serial_shipped_with_prior_document_commissioning_does_not_hard_block(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $prior = $this->createDocument(EpcisGuideline::R13);
            $shipping = $this->createDocument(EpcisGuideline::R13);

            $epc = Epc::query()->create(Epc::materializeAttributesFromUri(
                'urn:epc:id:sgtin:030116.3400516.POL-PRIOR-'.uniqid(),
            ));
            $this->epcIds[] = (int) $epc->getKey();

            $this->attachDocumentEpc($shipping, (int) $epc->getKey());
            $this->createCommissioningEvent($prior, (int) $epc->getKey());
            $this->openDocumentCase($shipping, 'SERIAL_SHIPPED_NOT_COMMISSIONED');

            $this->assertNull(
                app(ReceivingGate::class)->documentBlockedByOpenException($shipping->fresh()),
                'Commissioning on a prior tenant document must not HB receive.',
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function serial_shipped_without_commissioning_on_item_level_tenant_hard_blocks(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = $this->createDocument(EpcisGuideline::R13);
            $case = $this->openDocumentCase($document, 'SERIAL_SHIPPED_NOT_COMMISSIONED');

            $blocked = app(ReceivingGate::class)->documentBlockedByOpenException($document->fresh());
            $this->assertNotNull($blocked);
            $this->assertSame($case->getKey(), $blocked->getKey());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function events_out_of_order_does_not_block_receive(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = $this->createDocument(EpcisGuideline::R13);
            $this->openDocumentCase($document, 'EVENTS_OUT_OF_ORDER');

            $this->assertNull(
                app(ReceivingGate::class)->documentBlockedByOpenException($document->fresh()),
            );
        } finally {
            $this->cleanup();
        }
    }

    private function createDocument(EpcisGuideline $release): EpcisDocument
    {
        $attributes = [
            'document_uuid' => (string) str()->uuid(),
            'direction' => 'inbound',
            'creation_date' => now(),
            'received_at' => now(),
            'status' => 'validated',
            'dscsa_affirm' => true,
        ];
        if (Schema::hasColumn('epcis_documents', 'dscsa_guideline_release')) {
            $attributes['dscsa_guideline_release'] = $release;
        }

        $document = EpcisDocument::query()->create($attributes);
        $this->documentIds[] = (int) $document->getKey();

        return $document;
    }

    private function openDocumentCase(EpcisDocument $document, string $code): ExceptionCase
    {
        $type = ExceptionType::query()->where('code', $code)->first()
            ?? ExceptionTypeSeeder::ensure($code);
        $this->assertNotNull($type, $code.' must exist in the exception catalog.');

        $case = ExceptionCase::query()->create([
            'exception_type_id' => $type->getKey(),
            'document_id' => $document->getKey(),
            'title' => $type->name,
            'description' => 'Exception policy test '.$code,
            'severity' => $type->default_severity ?? ExceptionSeverity::High,
            'status' => ExceptionStatus::New,
        ]);
        $this->caseIds[] = (int) $case->getKey();

        return $case;
    }

    private function attachDocumentEpc(EpcisDocument $document, int $epcId): void
    {
        if (! Schema::hasTable('document_epcs')) {
            return;
        }

        DB::table('document_epcs')->insert([
            'document_id' => $document->getKey(),
            'epc_id' => $epcId,
            'ingest_generation' => (int) ($document->ingest_generation ?? 1),
        ]);
    }

    private function createCommissioningEvent(EpcisDocument $document, int $epcId): void
    {
        $event = EpcisEvent::query()->create([
            'document_id' => $document->getKey(),
            'event_type' => 'ObjectEvent',
            'event_time' => now()->subDay(),
            'record_time' => now()->subDay(),
            'action' => 'ADD',
            'biz_step' => 'urn:epcglobal:cbv:bizstep:commissioning',
        ]);
        $this->eventIds[] = (int) $event->getKey();

        DB::table('event_epcs')->insert([
            'event_id' => $event->getKey(),
            'epc_id' => $epcId,
            'role' => 'epcList',
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

        tenancy()->initialize($tenant);
        (new ExceptionTypeSeeder)->run();
        self::$demo2TenantReady = true;

        return $tenant;
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->caseIds !== []) {
            ExceptionCase::query()->whereIn('id', $this->caseIds)->delete();
            $this->caseIds = [];
        }

        if ($this->eventIds !== []) {
            DB::table('event_epcs')->whereIn('event_id', $this->eventIds)->delete();
            EpcisEvent::query()->whereIn('id', $this->eventIds)->delete();
            $this->eventIds = [];
        }

        if ($this->epcIds !== []) {
            if (Schema::hasTable('document_epcs')) {
                DB::table('document_epcs')->whereIn('epc_id', $this->epcIds)->delete();
            }
            Epc::query()->whereIn('id', $this->epcIds)->delete();
            $this->epcIds = [];
        }

        if ($this->documentIds !== []) {
            EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            $this->documentIds = [];
        }

        tenancy()->end();
    }
}
