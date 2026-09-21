<?php

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Epcis\ValidateEpcis12Document;
use App\Enums\ExceptionReceiveImpact;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcIlmd;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisDocumentProductClass;
use App\Models\Epcis\EpcisException;
use App\Models\Tenant;
use App\Support\Epcis\Validation\EpcisCatalogBusinessRules;
use App\Support\Epcis\Validation\EpcisValidationFinding;
use App\Support\Epcis\Validation\EpcisValidationProfileResolver;
use App\Support\Epcis\Validation\EpcisValidationSeverityMap;
use App\Support\Exceptions\ExceptionReceiveImpactMap;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ValidateEpcis12DocumentTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const SSCC_URI = 'urn:epc:id:sscc:030116.01001274303';

    private static bool $demo2TenantReady = false;

    private ?int $documentId = null;

    private ?string $redactedSgtin = null;

    #[Test]
    public function ingest_marks_commissioning_without_locations_as_error(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->assertTrue(Schema::hasTable('epcis_documents'));
            $this->assertTrue(Schema::hasTable('epcis_exceptions'));

            $fixture = base_path('tests/Fixtures/epcis/commissioning_sscc_missing_locations.xml');
            $this->assertFileExists($fixture);

            $tmp = tempnam(sys_get_temp_dir(), 'epcis_val_');
            $this->assertNotFalse($tmp);
            $xml = file_get_contents($fixture);
            $this->assertNotFalse($xml);
            $uuid = (string) str()->uuid();
            $xml = str_replace('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $uuid, $xml);
            file_put_contents($tmp, $xml);

            $document = app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'commissioning_sscc_missing_locations.xml',
            ]);
            $this->documentId = (int) $document->getKey();

            $this->assertSame('error', $document->status);

            $open = EpcisException::query()
                ->where('document_id', $document->id)
                ->where('status', 'open')
                ->where('exception_type', 'MISSING_MANDATORY_FIELD')
                ->get();

            $this->assertCount(1, $open);

            $this->assertTrue(
                $open->contains(fn (EpcisException $e): bool => str_contains((string) $e->description, 'readPoint')),
                'Expected open MISSING_MANDATORY_FIELD for readPoint',
            );
            $this->assertTrue(
                $open->contains(fn (EpcisException $e): bool => str_contains((string) $e->description, 'bizLocation')),
                'Expected open MISSING_MANDATORY_FIELD for bizLocation',
            );

            @unlink($tmp);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function direction_override_relaxes_dscsa_statement_requirement_for_outbound_without_shipping(): void
    {
        $this->initializeDemo2Tenant();

        try {
            // Fixture has commissioning + packing only (no shipping ObjectEvent) and no
            // affirmed DSCSA transaction statement, so the default (inbound) direction
            // always requires it, while an outbound override only requires it when a
            // shipping event is present.
            $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
            $this->assertFileExists($fixture);

            $tmp = tempnam(sys_get_temp_dir(), 'epcis_dir_').'.xml';
            $xml = file_get_contents($fixture);
            $this->assertNotFalse($xml);
            $xml = str_replace(
                '<gs1ushc:affirmTransactionStatement>true</gs1ushc:affirmTransactionStatement>',
                '<gs1ushc:affirmTransactionStatement>false</gs1ushc:affirmTransactionStatement>',
                $xml,
            );
            $uuid = (string) str()->uuid();
            $xml = str_replace('11111111-2222-3333-4444-555555555555', $uuid, $xml);
            file_put_contents($tmp, $xml);

            $document = app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'direction-override-outbound.xml',
            ]);
            $this->documentId = (int) $document->getKey();

            $this->assertSame('error', $document->status);
            $this->assertTrue(
                EpcisException::query()
                    ->where('document_id', $document->id)
                    ->where('status', 'open')
                    ->where('exception_type', 'MISSING_DSCSA_STATEMENT')
                    ->exists(),
                'Expected inbound (default direction) to require the DSCSA statement',
            );

            $absolutePath = Storage::disk($document->payload_disk)->path($document->payload_path);
            $findings = app(ValidateEpcis12Document::class)->handle($document, $absolutePath, 'outbound');

            $this->assertFalse(
                collect($findings)->contains(
                    fn (EpcisValidationFinding $f): bool => $f->exceptionType === 'MISSING_DSCSA_STATEMENT',
                ),
                'Outbound override without a shipping event should not require the DSCSA statement',
            );

            $document->refresh();
            $this->assertSame('validated', $document->status);
            $this->assertFalse(
                EpcisException::query()
                    ->where('document_id', $document->id)
                    ->where('status', 'open')
                    ->where('exception_type', 'MISSING_DSCSA_STATEMENT')
                    ->exists(),
                'Outbound revalidation should have cleared the previously-open MISSING_DSCSA_STATEMENT finding',
            );

            @unlink($tmp);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function serial_already_commissioned_only_flags_when_prior_commission_is_earlier(): void
    {
        $this->initializeDemo2Tenant();

        $earlyDocId = null;
        $lateDocId = null;
        $epcId = null;

        try {
            $suffix = (string) random_int(100000000, 999999999);
            $uri = 'urn:epc:id:sgtin:030116.0999999.'.$suffix;

            $epcId = (int) DB::table('epcs')->insertGetId([
                'epc_uri' => $uri,
                'epc_type' => 'sgtin',
                'company_prefix' => '030116',
                'indicator_digit' => '0',
                'item_reference' => '999999',
                'serial_number' => $suffix,
                'gtin14' => '00301169999995',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $earlyDoc = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'direction' => 'inbound',
                'ingest_generation' => 1,
                'status' => 'validated',
                'creation_date' => now(),
                'received_at' => now(),
                'original_filename' => 'early-commission.xml',
            ]);
            $lateDoc = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'direction' => 'inbound',
                'ingest_generation' => 1,
                'status' => 'validated',
                'creation_date' => now(),
                'received_at' => now(),
                'original_filename' => 'late-commission.xml',
            ]);
            $earlyDocId = (int) $earlyDoc->getKey();
            $lateDocId = (int) $lateDoc->getKey();

            foreach ([
                [$earlyDocId, '2025-01-01 10:00:00'],
                [$lateDocId, '2026-01-01 10:00:00'],
            ] as [$docId, $eventTime]) {
                $eventId = (int) DB::table('epcis_events')->insertGetId([
                    'document_id' => $docId,
                    'ingest_generation' => 1,
                    'event_id' => (string) str()->uuid(),
                    'event_type' => 'ObjectEvent',
                    'event_time' => $eventTime,
                    'action' => 'ADD',
                    'biz_step' => 'urn:epcglobal:cbv:bizstep:commissioning',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('event_epcs')->insert([
                    'event_id' => $eventId,
                    'epc_id' => $epcId,
                    'role' => 'epcList',
                ]);
                DB::table('document_epcs')->insert([
                    'document_id' => $docId,
                    'epc_id' => $epcId,
                    'ingest_generation' => 1,
                ]);
            }

            $rules = app(EpcisCatalogBusinessRules::class);
            $resolver = app(EpcisValidationProfileResolver::class);

            $earlyDoc = EpcisDocument::query()->findOrFail($earlyDocId);
            $earlyFindings = $rules->validate(
                $resolver->resolve($earlyDoc, 'inbound'),
                $earlyDoc->events()->get(),
            );
            $this->assertFalse(
                collect($earlyFindings)->contains(
                    fn (EpcisValidationFinding $f): bool => $f->exceptionType === 'SERIAL_ALREADY_COMMISSIONED',
                ),
                'Earlier commissioning document must not flag against a later duplicate commission',
            );

            $lateDoc = EpcisDocument::query()->findOrFail($lateDocId);
            $lateFindings = $rules->validate(
                $resolver->resolve($lateDoc, 'inbound'),
                $lateDoc->events()->get(),
            );
            $this->assertTrue(
                collect($lateFindings)->contains(
                    fn (EpcisValidationFinding $f): bool => $f->exceptionType === 'SERIAL_ALREADY_COMMISSIONED'
                        && $f->epcId === $epcId,
                ),
                'Later commissioning document must flag SERIAL_ALREADY_COMMISSIONED',
            );
        } finally {
            if ($earlyDocId !== null) {
                EpcisException::query()->where('document_id', $earlyDocId)->delete();
                DB::table('document_epcs')->where('document_id', $earlyDocId)->delete();
                $eventIds = DB::table('epcis_events')->where('document_id', $earlyDocId)->pluck('id');
                DB::table('event_epcs')->whereIn('event_id', $eventIds)->delete();
                DB::table('epcis_events')->where('document_id', $earlyDocId)->delete();
                DB::table('epcis_documents')->where('id', $earlyDocId)->delete();
            }
            if ($lateDocId !== null) {
                EpcisException::query()->where('document_id', $lateDocId)->delete();
                DB::table('document_epcs')->where('document_id', $lateDocId)->delete();
                $eventIds = DB::table('epcis_events')->where('document_id', $lateDocId)->pluck('id');
                DB::table('event_epcs')->whereIn('event_id', $eventIds)->delete();
                DB::table('epcis_events')->where('document_id', $lateDocId)->delete();
                DB::table('epcis_documents')->where('id', $lateDocId)->delete();
            }
            if ($epcId !== null && ! DB::table('event_epcs')->where('epc_id', $epcId)->exists()) {
                DB::table('epcs')->where('id', $epcId)->delete();
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function commission_after_ship_flags_when_packing_occurs_before_commission_in_same_document(): void
    {
        $this->initializeDemo2Tenant();

        $docId = null;
        $epcId = null;
        $packingEventId = null;

        try {
            $suffix = (string) random_int(100000000, 999999999);
            $uri = 'urn:epc:id:sgtin:030116.0999999.'.$suffix;

            $epcId = (int) DB::table('epcs')->insertGetId([
                'epc_uri' => $uri,
                'epc_type' => 'sgtin',
                'company_prefix' => '030116',
                'indicator_digit' => '0',
                'item_reference' => '999999',
                'serial_number' => $suffix,
                'gtin14' => '00301169999995',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $doc = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'direction' => 'inbound',
                'ingest_generation' => 1,
                'status' => 'validated',
                'creation_date' => now(),
                'received_at' => now(),
                'original_filename' => 'pack-before-commission.xml',
            ]);
            $docId = (int) $doc->getKey();

            $packingEventId = (int) DB::table('epcis_events')->insertGetId([
                'document_id' => $docId,
                'ingest_generation' => 1,
                'event_id' => (string) str()->uuid(),
                'event_type' => 'AggregationEvent',
                'event_time' => '2025-06-01 08:00:00',
                'action' => 'ADD',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:packing',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('event_epcs')->insert([
                'event_id' => $packingEventId,
                'epc_id' => $epcId,
                'role' => 'childEPC',
            ]);

            $commissionEventId = (int) DB::table('epcis_events')->insertGetId([
                'document_id' => $docId,
                'ingest_generation' => 1,
                'event_id' => (string) str()->uuid(),
                'event_type' => 'ObjectEvent',
                'event_time' => '2025-06-01 10:00:00',
                'action' => 'ADD',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:commissioning',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('event_epcs')->insert([
                'event_id' => $commissionEventId,
                'epc_id' => $epcId,
                'role' => 'epcList',
            ]);

            DB::table('document_epcs')->insert([
                'document_id' => $docId,
                'epc_id' => $epcId,
                'ingest_generation' => 1,
            ]);

            $rules = app(EpcisCatalogBusinessRules::class);
            $resolver = app(EpcisValidationProfileResolver::class);
            $doc = EpcisDocument::query()->findOrFail($docId);
            $findings = $rules->validate(
                $resolver->resolve($doc, 'inbound'),
                $doc->events()->get(),
            );

            $collection = collect($findings);

            $this->assertTrue(
                $collection->contains(
                    fn (EpcisValidationFinding $f): bool => $f->exceptionType === 'MISSING_COMMISSIONING'
                        && $f->epcId === $epcId
                        && $f->eventId === $packingEventId,
                ),
                'Packing before commissioning without readPoint must raise MISSING_COMMISSIONING on the packing event',
            );
            $this->assertFalse(
                $collection->contains(
                    fn (EpcisValidationFinding $f): bool => $f->exceptionType === 'COMMISSION_AFTER_SHIP'
                        && $f->epcId === $epcId,
                ),
                'MISSING_COMMISSIONING must suppress COMMISSION_AFTER_SHIP for the same EPC',
            );
        } finally {
            if ($docId !== null) {
                EpcisException::query()->where('document_id', $docId)->delete();
                DB::table('document_epcs')->where('document_id', $docId)->delete();
                $eventIds = DB::table('epcis_events')->where('document_id', $docId)->pluck('id');
                DB::table('event_epcs')->whereIn('event_id', $eventIds)->delete();
                DB::table('epcis_events')->where('document_id', $docId)->delete();
                DB::table('epcis_documents')->where('id', $docId)->delete();
            }
            if ($epcId !== null && ! DB::table('event_epcs')->where('epc_id', $epcId)->exists()) {
                DB::table('epcs')->where('id', $epcId)->delete();
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function commission_after_ship_flags_when_packing_parent_occurs_before_commission(): void
    {
        $this->initializeDemo2Tenant();

        $docId = null;
        $epcId = null;
        $packingEventId = null;

        try {
            $suffix = (string) random_int(100000000, 999999999);
            $uri = 'urn:epc:id:sgtin:030116.0999999.'.$suffix;

            $epcId = (int) DB::table('epcs')->insertGetId([
                'epc_uri' => $uri,
                'epc_type' => 'sgtin',
                'company_prefix' => '030116',
                'indicator_digit' => '0',
                'item_reference' => '999999',
                'serial_number' => $suffix,
                'gtin14' => '00301169999995',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $doc = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'direction' => 'inbound',
                'ingest_generation' => 1,
                'status' => 'validated',
                'creation_date' => now(),
                'received_at' => now(),
                'original_filename' => 'pack-parent-before-commission.xml',
            ]);
            $docId = (int) $doc->getKey();

            $packingEventId = (int) DB::table('epcis_events')->insertGetId([
                'document_id' => $docId,
                'ingest_generation' => 1,
                'event_id' => (string) str()->uuid(),
                'event_type' => 'AggregationEvent',
                'event_time' => '2025-06-01 08:00:00',
                'action' => 'ADD',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:packing',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('event_epcs')->insert([
                'event_id' => $packingEventId,
                'epc_id' => $epcId,
                'role' => 'parentID',
            ]);

            $commissionEventId = (int) DB::table('epcis_events')->insertGetId([
                'document_id' => $docId,
                'ingest_generation' => 1,
                'event_id' => (string) str()->uuid(),
                'event_type' => 'ObjectEvent',
                'event_time' => '2025-06-01 10:00:00',
                'action' => 'ADD',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:commissioning',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('event_epcs')->insert([
                'event_id' => $commissionEventId,
                'epc_id' => $epcId,
                'role' => 'epcList',
            ]);

            DB::table('document_epcs')->insert([
                'document_id' => $docId,
                'epc_id' => $epcId,
                'ingest_generation' => 1,
            ]);

            $rules = app(EpcisCatalogBusinessRules::class);
            $resolver = app(EpcisValidationProfileResolver::class);
            $doc = EpcisDocument::query()->findOrFail($docId);
            $findings = $rules->validate(
                $resolver->resolve($doc, 'inbound'),
                $doc->events()->get(),
            );

            $collection = collect($findings);

            $this->assertTrue(
                $collection->contains(
                    fn (EpcisValidationFinding $f): bool => $f->exceptionType === 'MISSING_COMMISSIONING'
                        && $f->epcId === $epcId
                        && $f->eventId === $packingEventId,
                ),
                'Packing as AggregationEvent parentID before commissioning without readPoint must raise MISSING_COMMISSIONING',
            );
            $this->assertFalse(
                $collection->contains(
                    fn (EpcisValidationFinding $f): bool => $f->exceptionType === 'COMMISSION_AFTER_SHIP'
                        && $f->epcId === $epcId,
                ),
                'MISSING_COMMISSIONING must suppress COMMISSION_AFTER_SHIP for the same EPC',
            );
        } finally {
            if ($docId !== null) {
                EpcisException::query()->where('document_id', $docId)->delete();
                DB::table('document_epcs')->where('document_id', $docId)->delete();
                $eventIds = DB::table('epcis_events')->where('document_id', $docId)->pluck('id');
                DB::table('event_epcs')->whereIn('event_id', $eventIds)->delete();
                DB::table('epcis_events')->where('document_id', $docId)->delete();
                DB::table('epcis_documents')->where('id', $docId)->delete();
            }
            if ($epcId !== null && ! DB::table('event_epcs')->where('epc_id', $epcId)->exists()) {
                DB::table('epcs')->where('id', $epcId)->delete();
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function commission_after_ship_does_not_flag_when_packing_occurs_after_commission(): void
    {
        $this->initializeDemo2Tenant();

        $docId = null;
        $epcId = null;

        try {
            $suffix = (string) random_int(100000000, 999999999);
            $uri = 'urn:epc:id:sgtin:030116.0999999.'.$suffix;

            $epcId = (int) DB::table('epcs')->insertGetId([
                'epc_uri' => $uri,
                'epc_type' => 'sgtin',
                'company_prefix' => '030116',
                'indicator_digit' => '0',
                'item_reference' => '999999',
                'serial_number' => $suffix,
                'gtin14' => '00301169999995',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $doc = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'direction' => 'inbound',
                'ingest_generation' => 1,
                'status' => 'validated',
                'creation_date' => now(),
                'received_at' => now(),
                'original_filename' => 'pack-after-commission.xml',
            ]);
            $docId = (int) $doc->getKey();

            $commissionEventId = (int) DB::table('epcis_events')->insertGetId([
                'document_id' => $docId,
                'ingest_generation' => 1,
                'event_id' => (string) str()->uuid(),
                'event_type' => 'ObjectEvent',
                'event_time' => '2025-06-01 08:00:00',
                'action' => 'ADD',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:commissioning',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('event_epcs')->insert([
                'event_id' => $commissionEventId,
                'epc_id' => $epcId,
                'role' => 'epcList',
            ]);

            $packingEventId = (int) DB::table('epcis_events')->insertGetId([
                'document_id' => $docId,
                'ingest_generation' => 1,
                'event_id' => (string) str()->uuid(),
                'event_type' => 'AggregationEvent',
                'event_time' => '2025-06-01 10:00:00',
                'action' => 'ADD',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:packing',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('event_epcs')->insert([
                'event_id' => $packingEventId,
                'epc_id' => $epcId,
                'role' => 'childEPC',
            ]);

            DB::table('document_epcs')->insert([
                'document_id' => $docId,
                'epc_id' => $epcId,
                'ingest_generation' => 1,
            ]);

            $rules = app(EpcisCatalogBusinessRules::class);
            $resolver = app(EpcisValidationProfileResolver::class);
            $doc = EpcisDocument::query()->findOrFail($docId);
            $findings = $rules->validate(
                $resolver->resolve($doc, 'inbound'),
                $doc->events()->get(),
            );

            $this->assertFalse(
                collect($findings)->contains(
                    fn (EpcisValidationFinding $f): bool => $f->exceptionType === 'COMMISSION_AFTER_SHIP'
                        && $f->epcId === $epcId,
                ),
                'Packing after commissioning must not raise COMMISSION_AFTER_SHIP',
            );
        } finally {
            if ($docId !== null) {
                EpcisException::query()->where('document_id', $docId)->delete();
                DB::table('document_epcs')->where('document_id', $docId)->delete();
                $eventIds = DB::table('epcis_events')->where('document_id', $docId)->pluck('id');
                DB::table('event_epcs')->whereIn('event_id', $eventIds)->delete();
                DB::table('epcis_events')->where('document_id', $docId)->delete();
                DB::table('epcis_documents')->where('id', $docId)->delete();
            }
            if ($epcId !== null && ! DB::table('event_epcs')->where('epc_id', $epcId)->exists()) {
                DB::table('epcs')->where('id', $epcId)->delete();
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function missing_commissioning_flags_sscc_packing_without_commission_in_same_document(): void
    {
        $this->initializeDemo2Tenant();

        $docId = null;
        $epcId = null;
        $packingEventId = null;

        try {
            $serial = (string) random_int(1000000000, 9999999999);
            $sscc18 = '0030116'.$serial;
            $uri = 'urn:epc:id:sscc:030116.'.$serial;

            $epcId = (int) DB::table('epcs')->insertGetId([
                'epc_uri' => $uri,
                'epc_type' => 'sscc',
                'company_prefix' => '030116',
                'extension_digit' => '0',
                'serial_number' => $serial,
                'sscc18' => $sscc18,
                'ai_00' => '0000'.$sscc18,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $doc = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'direction' => 'inbound',
                'ingest_generation' => 1,
                'status' => 'validated',
                'creation_date' => now(),
                'received_at' => now(),
                'original_filename' => 'sscc-pack-no-commission.xml',
            ]);
            $docId = (int) $doc->getKey();

            $packingEventId = (int) DB::table('epcis_events')->insertGetId([
                'document_id' => $docId,
                'ingest_generation' => 1,
                'event_id' => (string) str()->uuid(),
                'event_type' => 'AggregationEvent',
                'event_time' => '2025-06-01 08:00:00',
                'action' => 'ADD',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:packing',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('event_epcs')->insert([
                'event_id' => $packingEventId,
                'epc_id' => $epcId,
                'role' => 'parentID',
            ]);

            DB::table('document_epcs')->insert([
                'document_id' => $docId,
                'epc_id' => $epcId,
                'ingest_generation' => 1,
            ]);

            $rules = app(EpcisCatalogBusinessRules::class);
            $resolver = app(EpcisValidationProfileResolver::class);
            $doc = EpcisDocument::query()->findOrFail($docId);
            $findings = $rules->validate(
                $resolver->resolve($doc, 'inbound'),
                $doc->events()->get(),
            );

            $this->assertTrue(
                collect($findings)->contains(
                    fn (EpcisValidationFinding $f): bool => $f->exceptionType === 'MISSING_COMMISSIONING'
                        && $f->epcId === $epcId
                        && $f->eventId === $packingEventId,
                ),
                'SSCC packing without in-document commissioning must raise MISSING_COMMISSIONING on the packing event',
            );
        } finally {
            if ($docId !== null) {
                EpcisException::query()->where('document_id', $docId)->delete();
                DB::table('document_epcs')->where('document_id', $docId)->delete();
                $eventIds = DB::table('epcis_events')->where('document_id', $docId)->pluck('id');
                DB::table('event_epcs')->whereIn('event_id', $eventIds)->delete();
                DB::table('epcis_events')->where('document_id', $docId)->delete();
                DB::table('epcis_documents')->where('id', $docId)->delete();
            }
            if ($epcId !== null && ! DB::table('event_epcs')->where('epc_id', $epcId)->exists()) {
                DB::table('epcs')->where('id', $epcId)->delete();
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function missing_commissioning_flags_sscc_packing_when_commission_lacks_read_point(): void
    {
        $this->initializeDemo2Tenant();

        $docId = null;
        $epcId = null;
        $packingEventId = null;

        try {
            $serial = (string) random_int(1000000000, 9999999999);
            $sscc18 = '0030116'.$serial;
            $uri = 'urn:epc:id:sscc:030116.'.$serial;

            $epcId = (int) DB::table('epcs')->insertGetId([
                'epc_uri' => $uri,
                'epc_type' => 'sscc',
                'company_prefix' => '030116',
                'extension_digit' => '0',
                'serial_number' => $serial,
                'sscc18' => $sscc18,
                'ai_00' => '0000'.$sscc18,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $doc = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'direction' => 'inbound',
                'ingest_generation' => 1,
                'status' => 'validated',
                'creation_date' => now(),
                'received_at' => now(),
                'original_filename' => 'sscc-pack-commission-no-readpoint.xml',
            ]);
            $docId = (int) $doc->getKey();

            $packingEventId = (int) DB::table('epcis_events')->insertGetId([
                'document_id' => $docId,
                'ingest_generation' => 1,
                'event_id' => (string) str()->uuid(),
                'event_type' => 'AggregationEvent',
                'event_time' => '2025-06-01 10:00:00',
                'action' => 'ADD',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:packing',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('event_epcs')->insert([
                'event_id' => $packingEventId,
                'epc_id' => $epcId,
                'role' => 'parentID',
            ]);

            $commissionEventId = (int) DB::table('epcis_events')->insertGetId([
                'document_id' => $docId,
                'ingest_generation' => 1,
                'event_id' => (string) str()->uuid(),
                'event_type' => 'ObjectEvent',
                'event_time' => '2025-06-01 08:00:00',
                'action' => 'ADD',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:commissioning',
                'read_point_gln' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('event_epcs')->insert([
                'event_id' => $commissionEventId,
                'epc_id' => $epcId,
                'role' => 'epcList',
            ]);

            DB::table('document_epcs')->insert([
                'document_id' => $docId,
                'epc_id' => $epcId,
                'ingest_generation' => 1,
            ]);

            $rules = app(EpcisCatalogBusinessRules::class);
            $resolver = app(EpcisValidationProfileResolver::class);
            $doc = EpcisDocument::query()->findOrFail($docId);
            $findings = $rules->validate(
                $resolver->resolve($doc, 'inbound'),
                $doc->events()->get(),
            );

            $this->assertTrue(
                collect($findings)->contains(
                    fn (EpcisValidationFinding $f): bool => $f->exceptionType === 'MISSING_COMMISSIONING'
                        && $f->epcId === $epcId
                        && $f->eventId === $packingEventId
                        && str_contains(strtolower($f->description), 'readpoint'),
                ),
                'SSCC packing after commissioning without readPoint must raise MISSING_COMMISSIONING (7309 pattern)',
            );
        } finally {
            if ($docId !== null) {
                EpcisException::query()->where('document_id', $docId)->delete();
                DB::table('document_epcs')->where('document_id', $docId)->delete();
                $eventIds = DB::table('epcis_events')->where('document_id', $docId)->pluck('id');
                DB::table('event_epcs')->whereIn('event_id', $eventIds)->delete();
                DB::table('epcis_events')->where('document_id', $docId)->delete();
                DB::table('epcis_documents')->where('id', $docId)->delete();
            }
            if ($epcId !== null && ! DB::table('event_epcs')->where('epc_id', $epcId)->exists()) {
                DB::table('epcs')->where('id', $epcId)->delete();
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function missing_commissioning_does_not_flag_sscc_packing_when_commission_has_read_point(): void
    {
        $this->initializeDemo2Tenant();

        $docId = null;
        $epcId = null;

        try {
            $serial = (string) random_int(1000000000, 9999999999);
            $sscc18 = '0030116'.$serial;
            $uri = 'urn:epc:id:sscc:030116.'.$serial;

            $epcId = (int) DB::table('epcs')->insertGetId([
                'epc_uri' => $uri,
                'epc_type' => 'sscc',
                'company_prefix' => '030116',
                'extension_digit' => '0',
                'serial_number' => $serial,
                'sscc18' => $sscc18,
                'ai_00' => '0000'.$sscc18,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $doc = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'direction' => 'inbound',
                'ingest_generation' => 1,
                'status' => 'validated',
                'creation_date' => now(),
                'received_at' => now(),
                'original_filename' => 'sscc-pack-commission-with-readpoint.xml',
            ]);
            $docId = (int) $doc->getKey();

            $commissionEventId = (int) DB::table('epcis_events')->insertGetId([
                'document_id' => $docId,
                'ingest_generation' => 1,
                'event_id' => (string) str()->uuid(),
                'event_type' => 'ObjectEvent',
                'event_time' => '2025-06-01 08:00:00',
                'action' => 'ADD',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:commissioning',
                'read_point_gln' => '0301160000005',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('event_epcs')->insert([
                'event_id' => $commissionEventId,
                'epc_id' => $epcId,
                'role' => 'epcList',
            ]);

            $packingEventId = (int) DB::table('epcis_events')->insertGetId([
                'document_id' => $docId,
                'ingest_generation' => 1,
                'event_id' => (string) str()->uuid(),
                'event_type' => 'AggregationEvent',
                'event_time' => '2025-06-01 10:00:00',
                'action' => 'ADD',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:packing',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('event_epcs')->insert([
                'event_id' => $packingEventId,
                'epc_id' => $epcId,
                'role' => 'parentID',
            ]);

            DB::table('document_epcs')->insert([
                'document_id' => $docId,
                'epc_id' => $epcId,
                'ingest_generation' => 1,
            ]);

            $rules = app(EpcisCatalogBusinessRules::class);
            $resolver = app(EpcisValidationProfileResolver::class);
            $doc = EpcisDocument::query()->findOrFail($docId);
            $findings = $rules->validate(
                $resolver->resolve($doc, 'inbound'),
                $doc->events()->get(),
            );

            $this->assertFalse(
                collect($findings)->contains(
                    fn (EpcisValidationFinding $f): bool => $f->exceptionType === 'MISSING_COMMISSIONING'
                        && $f->epcId === $epcId,
                ),
                'SSCC packing after commissioning with readPoint must not raise MISSING_COMMISSIONING',
            );
        } finally {
            if ($docId !== null) {
                EpcisException::query()->where('document_id', $docId)->delete();
                DB::table('document_epcs')->where('document_id', $docId)->delete();
                $eventIds = DB::table('epcis_events')->where('document_id', $docId)->pluck('id');
                DB::table('event_epcs')->whereIn('event_id', $eventIds)->delete();
                DB::table('epcis_events')->where('document_id', $docId)->delete();
                DB::table('epcis_documents')->where('id', $docId)->delete();
            }
            if ($epcId !== null && ! DB::table('event_epcs')->where('epc_id', $epcId)->exists()) {
                DB::table('epcs')->where('id', $epcId)->delete();
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function serial_shipped_not_commissioned_flags_sscc_shipping_without_commission_anywhere(): void
    {
        $this->initializeDemo2Tenant();

        $docId = null;
        $epcId = null;

        try {
            $serial = (string) random_int(1000000000, 9999999999);
            $sscc18 = '0030116'.$serial;
            $uri = 'urn:epc:id:sscc:030116.'.$serial;

            $epcId = (int) DB::table('epcs')->insertGetId([
                'epc_uri' => $uri,
                'epc_type' => 'sscc',
                'company_prefix' => '030116',
                'extension_digit' => '0',
                'serial_number' => $serial,
                'sscc18' => $sscc18,
                'ai_00' => '0000'.$sscc18,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $doc = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'direction' => 'inbound',
                'ingest_generation' => 1,
                'status' => 'validated',
                'creation_date' => now(),
                'received_at' => now(),
                'original_filename' => 'sscc-ship-no-commission.xml',
            ]);
            $docId = (int) $doc->getKey();

            $shipEventId = (int) DB::table('epcis_events')->insertGetId([
                'document_id' => $docId,
                'ingest_generation' => 1,
                'event_id' => (string) str()->uuid(),
                'event_type' => 'ObjectEvent',
                'event_time' => '2025-06-01 08:00:00',
                'action' => 'OBSERVE',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:shipping',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('event_epcs')->insert([
                'event_id' => $shipEventId,
                'epc_id' => $epcId,
                'role' => 'epcList',
            ]);

            DB::table('document_epcs')->insert([
                'document_id' => $docId,
                'epc_id' => $epcId,
                'ingest_generation' => 1,
            ]);

            $rules = app(EpcisCatalogBusinessRules::class);
            $resolver = app(EpcisValidationProfileResolver::class);
            $doc = EpcisDocument::query()->findOrFail($docId);
            $findings = $rules->validate(
                $resolver->resolve($doc, 'inbound'),
                $doc->events()->get(),
            );

            $this->assertTrue(
                collect($findings)->contains(
                    fn (EpcisValidationFinding $f): bool => $f->exceptionType === 'SERIAL_SHIPPED_NOT_COMMISSIONED'
                        && $f->epcId === $epcId,
                ),
                'SSCC shipped with no commissioning anywhere must raise SERIAL_SHIPPED_NOT_COMMISSIONED',
            );
        } finally {
            if ($docId !== null) {
                EpcisException::query()->where('document_id', $docId)->delete();
                DB::table('document_epcs')->where('document_id', $docId)->delete();
                $eventIds = DB::table('epcis_events')->where('document_id', $docId)->pluck('id');
                DB::table('event_epcs')->whereIn('event_id', $eventIds)->delete();
                DB::table('epcis_events')->where('document_id', $docId)->delete();
                DB::table('epcis_documents')->where('id', $docId)->delete();
            }
            if ($epcId !== null && ! DB::table('event_epcs')->where('epc_id', $epcId)->exists()) {
                DB::table('epcs')->where('id', $epcId)->delete();
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function commission_after_ship_still_flags_when_shipping_occurs_before_commission(): void
    {
        $this->initializeDemo2Tenant();

        $docId = null;
        $epcId = null;
        $shipEventId = null;

        try {
            $suffix = (string) random_int(100000000, 999999999);
            $uri = 'urn:epc:id:sgtin:030116.0999999.'.$suffix;

            $epcId = (int) DB::table('epcs')->insertGetId([
                'epc_uri' => $uri,
                'epc_type' => 'sgtin',
                'company_prefix' => '030116',
                'indicator_digit' => '0',
                'item_reference' => '999999',
                'serial_number' => $suffix,
                'gtin14' => '00301169999995',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $doc = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'direction' => 'inbound',
                'ingest_generation' => 1,
                'status' => 'validated',
                'creation_date' => now(),
                'received_at' => now(),
                'original_filename' => 'ship-before-commission.xml',
            ]);
            $docId = (int) $doc->getKey();

            $shipEventId = (int) DB::table('epcis_events')->insertGetId([
                'document_id' => $docId,
                'ingest_generation' => 1,
                'event_id' => (string) str()->uuid(),
                'event_type' => 'ObjectEvent',
                'event_time' => '2025-06-01 08:00:00',
                'action' => 'OBSERVE',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:shipping',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('event_epcs')->insert([
                'event_id' => $shipEventId,
                'epc_id' => $epcId,
                'role' => 'epcList',
            ]);

            $commissionEventId = (int) DB::table('epcis_events')->insertGetId([
                'document_id' => $docId,
                'ingest_generation' => 1,
                'event_id' => (string) str()->uuid(),
                'event_type' => 'ObjectEvent',
                'event_time' => '2025-06-01 10:00:00',
                'action' => 'ADD',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:commissioning',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('event_epcs')->insert([
                'event_id' => $commissionEventId,
                'epc_id' => $epcId,
                'role' => 'epcList',
            ]);

            DB::table('document_epcs')->insert([
                'document_id' => $docId,
                'epc_id' => $epcId,
                'ingest_generation' => 1,
            ]);

            $rules = app(EpcisCatalogBusinessRules::class);
            $resolver = app(EpcisValidationProfileResolver::class);
            $doc = EpcisDocument::query()->findOrFail($docId);
            $findings = $rules->validate(
                $resolver->resolve($doc, 'inbound'),
                $doc->events()->get(),
            );

            $collection = collect($findings);

            $this->assertTrue(
                $collection->contains(
                    fn (EpcisValidationFinding $f): bool => $f->exceptionType === 'MISSING_COMMISSIONING'
                        && $f->epcId === $epcId
                        && $f->eventId === $shipEventId,
                ),
                'Shipping before commissioning without readPoint must raise MISSING_COMMISSIONING on the shipping event',
            );
            $this->assertFalse(
                $collection->contains(
                    fn (EpcisValidationFinding $f): bool => $f->exceptionType === 'COMMISSION_AFTER_SHIP'
                        && $f->epcId === $epcId,
                ),
                'MISSING_COMMISSIONING must suppress COMMISSION_AFTER_SHIP for the same EPC',
            );
        } finally {
            if ($docId !== null) {
                EpcisException::query()->where('document_id', $docId)->delete();
                DB::table('document_epcs')->where('document_id', $docId)->delete();
                $eventIds = DB::table('epcis_events')->where('document_id', $docId)->pluck('id');
                DB::table('event_epcs')->whereIn('event_id', $eventIds)->delete();
                DB::table('epcis_events')->where('document_id', $docId)->delete();
                DB::table('epcis_documents')->where('id', $docId)->delete();
            }
            if ($epcId !== null && ! DB::table('event_epcs')->where('epc_id', $epcId)->exists()) {
                DB::table('epcs')->where('id', $epcId)->delete();
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function commission_after_ship_flags_when_shipping_before_commission_with_usable_read_point(): void
    {
        $this->initializeDemo2Tenant();

        $docId = null;
        $epcId = null;
        $shipEventId = null;

        try {
            $suffix = (string) random_int(100000000, 999999999);
            $uri = 'urn:epc:id:sgtin:030116.0999999.'.$suffix;

            $epcId = (int) DB::table('epcs')->insertGetId([
                'epc_uri' => $uri,
                'epc_type' => 'sgtin',
                'company_prefix' => '030116',
                'indicator_digit' => '0',
                'item_reference' => '999999',
                'serial_number' => $suffix,
                'gtin14' => '00301169999995',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $doc = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'direction' => 'inbound',
                'ingest_generation' => 1,
                'status' => 'validated',
                'creation_date' => now(),
                'received_at' => now(),
                'original_filename' => 'ship-before-commission-with-readpoint.xml',
            ]);
            $docId = (int) $doc->getKey();

            $shipEventId = (int) DB::table('epcis_events')->insertGetId([
                'document_id' => $docId,
                'ingest_generation' => 1,
                'event_id' => (string) str()->uuid(),
                'event_type' => 'ObjectEvent',
                'event_time' => '2025-06-01 08:00:00',
                'action' => 'OBSERVE',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:shipping',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('event_epcs')->insert([
                'event_id' => $shipEventId,
                'epc_id' => $epcId,
                'role' => 'epcList',
            ]);

            $commissionEventId = (int) DB::table('epcis_events')->insertGetId([
                'document_id' => $docId,
                'ingest_generation' => 1,
                'event_id' => (string) str()->uuid(),
                'event_type' => 'ObjectEvent',
                'event_time' => '2025-06-01 10:00:00',
                'action' => 'ADD',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:commissioning',
                'read_point_gln' => '0301160000005',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('event_epcs')->insert([
                'event_id' => $commissionEventId,
                'epc_id' => $epcId,
                'role' => 'epcList',
            ]);

            DB::table('document_epcs')->insert([
                'document_id' => $docId,
                'epc_id' => $epcId,
                'ingest_generation' => 1,
            ]);

            $rules = app(EpcisCatalogBusinessRules::class);
            $resolver = app(EpcisValidationProfileResolver::class);
            $doc = EpcisDocument::query()->findOrFail($docId);
            $findings = $rules->validate(
                $resolver->resolve($doc, 'inbound'),
                $doc->events()->get(),
            );

            $collection = collect($findings);

            $this->assertTrue(
                $collection->contains(
                    fn (EpcisValidationFinding $f): bool => $f->exceptionType === 'COMMISSION_AFTER_SHIP'
                        && $f->epcId === $epcId
                        && $f->eventId === $shipEventId,
                ),
                'Shipping before commissioning with usable readPoint must raise COMMISSION_AFTER_SHIP',
            );
            $this->assertFalse(
                $collection->contains(
                    fn (EpcisValidationFinding $f): bool => $f->exceptionType === 'MISSING_COMMISSIONING'
                        && $f->epcId === $epcId,
                ),
                'Usable commissioning readPoint must not raise MISSING_COMMISSIONING',
            );
        } finally {
            if ($docId !== null) {
                EpcisException::query()->where('document_id', $docId)->delete();
                DB::table('document_epcs')->where('document_id', $docId)->delete();
                $eventIds = DB::table('epcis_events')->where('document_id', $docId)->pluck('id');
                DB::table('event_epcs')->whereIn('event_id', $eventIds)->delete();
                DB::table('epcis_events')->where('document_id', $docId)->delete();
                DB::table('epcis_documents')->where('id', $docId)->delete();
            }
            if ($epcId !== null && ! DB::table('event_epcs')->where('epc_id', $epcId)->exists()) {
                DB::table('epcs')->where('id', $epcId)->delete();
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function pack_hierarchy_time_inversion_flags_when_child_pack_precedes_parent_pack(): void
    {
        $this->initializeDemo2Tenant();

        $docId = null;
        $epcId = null;

        try {
            $suffix = (string) random_int(100000000, 999999999);
            $uri = 'urn:epc:id:sgtin:030116.0888888.'.$suffix;

            $epcId = (int) DB::table('epcs')->insertGetId([
                'epc_uri' => $uri,
                'epc_type' => 'sgtin',
                'company_prefix' => '030116',
                'indicator_digit' => '0',
                'item_reference' => '888888',
                'serial_number' => $suffix,
                'gtin14' => '00301168888884',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $doc = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'direction' => 'inbound',
                'ingest_generation' => 1,
                'status' => 'parsed',
                'creation_date' => now(),
                'received_at' => now(),
                'original_filename' => 'pack-hierarchy-inversion.xml',
            ]);
            $docId = (int) $doc->getKey();

            $intoSsccEventId = (int) DB::table('epcis_events')->insertGetId([
                'document_id' => $docId,
                'ingest_generation' => 1,
                'event_id' => (string) str()->uuid(),
                'event_type' => 'AggregationEvent',
                'event_time' => '2026-08-13 16:46:16',
                'action' => 'ADD',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:packing',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('event_epcs')->insert([
                'event_id' => $intoSsccEventId,
                'epc_id' => $epcId,
                'role' => 'childEPC',
            ]);

            $eaIntoInnerEventId = (int) DB::table('epcis_events')->insertGetId([
                'document_id' => $docId,
                'ingest_generation' => 1,
                'event_id' => (string) str()->uuid(),
                'event_type' => 'AggregationEvent',
                'event_time' => '2026-08-13 16:46:36',
                'action' => 'ADD',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:packing',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('event_epcs')->insert([
                'event_id' => $eaIntoInnerEventId,
                'epc_id' => $epcId,
                'role' => 'parentID',
            ]);

            DB::table('document_epcs')->insert([
                'document_id' => $docId,
                'epc_id' => $epcId,
                'ingest_generation' => 1,
            ]);

            $rules = app(EpcisCatalogBusinessRules::class);
            $resolver = app(EpcisValidationProfileResolver::class);
            $doc = EpcisDocument::query()->findOrFail($docId);
            $findings = $rules->validate(
                $resolver->resolve($doc, 'inbound'),
                $doc->events()->get(),
            );

            $this->assertTrue(
                collect($findings)->contains(
                    fn (EpcisValidationFinding $f): bool => $f->exceptionType === 'PACK_HIERARCHY_TIME_INVERSION'
                        && $f->epcId === $epcId
                        && $f->eventId === $intoSsccEventId
                        && $f->severity === 'error',
                ),
                'Inner pack timed into SSCC before EA→inner must raise PACK_HIERARCHY_TIME_INVERSION',
            );
            $this->assertSame(
                ExceptionReceiveImpact::BusinessRule,
                ExceptionReceiveImpactMap::forCode('PACK_HIERARCHY_TIME_INVERSION'),
            );
        } finally {
            if ($docId !== null) {
                EpcisException::query()->where('document_id', $docId)->delete();
                DB::table('document_epcs')->where('document_id', $docId)->delete();
                $eventIds = DB::table('epcis_events')->where('document_id', $docId)->pluck('id');
                DB::table('event_epcs')->whereIn('event_id', $eventIds)->delete();
                DB::table('epcis_events')->where('document_id', $docId)->delete();
                DB::table('epcis_documents')->where('id', $docId)->delete();
            }
            if ($epcId !== null && ! DB::table('event_epcs')->where('epc_id', $epcId)->exists()) {
                DB::table('epcs')->where('id', $epcId)->delete();
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function pack_hierarchy_time_inversion_does_not_flag_when_parent_pack_precedes_child_pack(): void
    {
        $this->initializeDemo2Tenant();

        $docId = null;
        $epcId = null;

        try {
            $suffix = (string) random_int(100000000, 999999999);
            $uri = 'urn:epc:id:sgtin:030116.0777777.'.$suffix;

            $epcId = (int) DB::table('epcs')->insertGetId([
                'epc_uri' => $uri,
                'epc_type' => 'sgtin',
                'company_prefix' => '030116',
                'indicator_digit' => '0',
                'item_reference' => '777777',
                'serial_number' => $suffix,
                'gtin14' => '00301167777773',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $doc = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'direction' => 'inbound',
                'ingest_generation' => 1,
                'status' => 'parsed',
                'creation_date' => now(),
                'received_at' => now(),
                'original_filename' => 'pack-hierarchy-ok.xml',
            ]);
            $docId = (int) $doc->getKey();

            $eaIntoInnerEventId = (int) DB::table('epcis_events')->insertGetId([
                'document_id' => $docId,
                'ingest_generation' => 1,
                'event_id' => (string) str()->uuid(),
                'event_type' => 'AggregationEvent',
                'event_time' => '2026-08-13 16:46:16',
                'action' => 'ADD',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:packing',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('event_epcs')->insert([
                'event_id' => $eaIntoInnerEventId,
                'epc_id' => $epcId,
                'role' => 'parentID',
            ]);

            $intoSsccEventId = (int) DB::table('epcis_events')->insertGetId([
                'document_id' => $docId,
                'ingest_generation' => 1,
                'event_id' => (string) str()->uuid(),
                'event_type' => 'AggregationEvent',
                'event_time' => '2026-08-13 16:46:36',
                'action' => 'ADD',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:packing',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('event_epcs')->insert([
                'event_id' => $intoSsccEventId,
                'epc_id' => $epcId,
                'role' => 'childEPC',
            ]);

            DB::table('document_epcs')->insert([
                'document_id' => $docId,
                'epc_id' => $epcId,
                'ingest_generation' => 1,
            ]);

            $rules = app(EpcisCatalogBusinessRules::class);
            $resolver = app(EpcisValidationProfileResolver::class);
            $doc = EpcisDocument::query()->findOrFail($docId);
            $findings = $rules->validate(
                $resolver->resolve($doc, 'inbound'),
                $doc->events()->get(),
            );

            $this->assertFalse(
                collect($findings)->contains(
                    fn (EpcisValidationFinding $f): bool => $f->exceptionType === 'PACK_HIERARCHY_TIME_INVERSION'
                        && $f->epcId === $epcId,
                ),
                'Correct EA→inner then inner→SSCC order must not raise PACK_HIERARCHY_TIME_INVERSION',
            );
        } finally {
            if ($docId !== null) {
                EpcisException::query()->where('document_id', $docId)->delete();
                DB::table('document_epcs')->where('document_id', $docId)->delete();
                $eventIds = DB::table('epcis_events')->where('document_id', $docId)->pluck('id');
                DB::table('event_epcs')->whereIn('event_id', $eventIds)->delete();
                DB::table('epcis_events')->where('document_id', $docId)->delete();
                DB::table('epcis_documents')->where('id', $docId)->delete();
            }
            if ($epcId !== null && ! DB::table('event_epcs')->where('epc_id', $epcId)->exists()) {
                DB::table('epcs')->where('id', $epcId)->delete();
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function missing_commissioning_flags_receiving_without_commission_in_same_document(): void
    {
        $this->initializeDemo2Tenant();

        $docId = null;
        $epcId = null;
        $receivingEventId = null;

        try {
            $suffix = (string) random_int(100000000, 999999999);
            $uri = 'urn:epc:id:sgtin:030116.0999999.'.$suffix;

            $epcId = (int) DB::table('epcs')->insertGetId([
                'epc_uri' => $uri,
                'epc_type' => 'sgtin',
                'company_prefix' => '030116',
                'indicator_digit' => '0',
                'item_reference' => '999999',
                'serial_number' => $suffix,
                'gtin14' => '00301169999995',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $doc = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'direction' => 'inbound',
                'ingest_generation' => 1,
                'status' => 'validated',
                'creation_date' => now(),
                'received_at' => now(),
                'original_filename' => 'receive-no-commission.xml',
            ]);
            $docId = (int) $doc->getKey();

            $receivingEventId = (int) DB::table('epcis_events')->insertGetId([
                'document_id' => $docId,
                'ingest_generation' => 1,
                'event_id' => (string) str()->uuid(),
                'event_type' => 'ObjectEvent',
                'event_time' => '2025-06-01 08:00:00',
                'action' => 'OBSERVE',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:receiving',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('event_epcs')->insert([
                'event_id' => $receivingEventId,
                'epc_id' => $epcId,
                'role' => 'epcList',
            ]);

            DB::table('document_epcs')->insert([
                'document_id' => $docId,
                'epc_id' => $epcId,
                'ingest_generation' => 1,
            ]);

            $rules = app(EpcisCatalogBusinessRules::class);
            $resolver = app(EpcisValidationProfileResolver::class);
            $doc = EpcisDocument::query()->findOrFail($docId);
            $findings = $rules->validate(
                $resolver->resolve($doc, 'inbound'),
                $doc->events()->get(),
            );

            $this->assertTrue(
                collect($findings)->contains(
                    fn (EpcisValidationFinding $f): bool => $f->exceptionType === 'MISSING_COMMISSIONING'
                        && $f->epcId === $epcId
                        && $f->eventId === $receivingEventId,
                ),
                'Receiving without in-document commissioning must raise MISSING_COMMISSIONING on the receiving event',
            );
        } finally {
            if ($docId !== null) {
                EpcisException::query()->where('document_id', $docId)->delete();
                DB::table('document_epcs')->where('document_id', $docId)->delete();
                $eventIds = DB::table('epcis_events')->where('document_id', $docId)->pluck('id');
                DB::table('event_epcs')->whereIn('event_id', $eventIds)->delete();
                DB::table('epcis_events')->where('document_id', $docId)->delete();
                DB::table('epcis_documents')->where('id', $docId)->delete();
            }
            if ($epcId !== null && ! DB::table('event_epcs')->where('epc_id', $epcId)->exists()) {
                DB::table('epcs')->where('id', $epcId)->delete();
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function missing_commissioning_does_not_flag_receiving_when_commission_has_read_point(): void
    {
        $this->initializeDemo2Tenant();

        $docId = null;
        $epcId = null;

        try {
            $suffix = (string) random_int(100000000, 999999999);
            $uri = 'urn:epc:id:sgtin:030116.0999999.'.$suffix;

            $epcId = (int) DB::table('epcs')->insertGetId([
                'epc_uri' => $uri,
                'epc_type' => 'sgtin',
                'company_prefix' => '030116',
                'indicator_digit' => '0',
                'item_reference' => '999999',
                'serial_number' => $suffix,
                'gtin14' => '00301169999995',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $doc = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'direction' => 'inbound',
                'ingest_generation' => 1,
                'status' => 'validated',
                'creation_date' => now(),
                'received_at' => now(),
                'original_filename' => 'receive-with-commission-readpoint.xml',
            ]);
            $docId = (int) $doc->getKey();

            $commissionEventId = (int) DB::table('epcis_events')->insertGetId([
                'document_id' => $docId,
                'ingest_generation' => 1,
                'event_id' => (string) str()->uuid(),
                'event_type' => 'ObjectEvent',
                'event_time' => '2025-06-01 08:00:00',
                'action' => 'ADD',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:commissioning',
                'read_point_gln' => '0301160000005',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('event_epcs')->insert([
                'event_id' => $commissionEventId,
                'epc_id' => $epcId,
                'role' => 'epcList',
            ]);

            $receivingEventId = (int) DB::table('epcis_events')->insertGetId([
                'document_id' => $docId,
                'ingest_generation' => 1,
                'event_id' => (string) str()->uuid(),
                'event_type' => 'ObjectEvent',
                'event_time' => '2025-06-01 10:00:00',
                'action' => 'OBSERVE',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:receiving',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('event_epcs')->insert([
                'event_id' => $receivingEventId,
                'epc_id' => $epcId,
                'role' => 'epcList',
            ]);

            DB::table('document_epcs')->insert([
                'document_id' => $docId,
                'epc_id' => $epcId,
                'ingest_generation' => 1,
            ]);

            $rules = app(EpcisCatalogBusinessRules::class);
            $resolver = app(EpcisValidationProfileResolver::class);
            $doc = EpcisDocument::query()->findOrFail($docId);
            $findings = $rules->validate(
                $resolver->resolve($doc, 'inbound'),
                $doc->events()->get(),
            );

            $this->assertFalse(
                collect($findings)->contains(
                    fn (EpcisValidationFinding $f): bool => $f->exceptionType === 'MISSING_COMMISSIONING'
                        && $f->epcId === $epcId,
                ),
                'Receiving after commissioning with readPoint must not raise MISSING_COMMISSIONING',
            );
        } finally {
            if ($docId !== null) {
                EpcisException::query()->where('document_id', $docId)->delete();
                DB::table('document_epcs')->where('document_id', $docId)->delete();
                $eventIds = DB::table('epcis_events')->where('document_id', $docId)->pluck('id');
                DB::table('event_epcs')->whereIn('event_id', $eventIds)->delete();
                DB::table('epcis_events')->where('document_id', $docId)->delete();
                DB::table('epcis_documents')->where('id', $docId)->delete();
            }
            if ($epcId !== null && ! DB::table('event_epcs')->where('epc_id', $epcId)->exists()) {
                DB::table('epcs')->where('id', $epcId)->delete();
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function findings_truncated_emits_overflow_when_per_type_cap_exceeded(): void
    {
        $this->initializeDemo2Tenant();

        config(['tracepharma.epcis.validation.max_findings_per_type' => 2]);

        $docId = null;
        $epcIds = [];

        try {
            $doc = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'direction' => 'inbound',
                'ingest_generation' => 1,
                'status' => 'validated',
                'creation_date' => now(),
                'received_at' => now(),
                'original_filename' => 'orphan-sscc-overflow.xml',
            ]);
            $docId = (int) $doc->getKey();

            for ($i = 0; $i < 5; $i++) {
                $serial = (string) random_int(1000000000, 9999999999);
                $sscc18 = '0030116'.$serial;
                $uri = 'urn:epc:id:sscc:030116.'.$serial;

                $epcId = (int) DB::table('epcs')->insertGetId([
                    'epc_uri' => $uri,
                    'epc_type' => 'sscc',
                    'company_prefix' => '030116',
                    'extension_digit' => '0',
                    'serial_number' => $serial,
                    'sscc18' => $sscc18,
                    'ai_00' => '0000'.$sscc18,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $epcIds[] = $epcId;

                $commissionEventId = (int) DB::table('epcis_events')->insertGetId([
                    'document_id' => $docId,
                    'ingest_generation' => 1,
                    'event_id' => (string) str()->uuid(),
                    'event_type' => 'ObjectEvent',
                    'event_time' => '2025-06-01 08:00:00',
                    'action' => 'ADD',
                    'biz_step' => 'urn:epcglobal:cbv:bizstep:commissioning',
                    'read_point_gln' => '0301160000005',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('event_epcs')->insert([
                    'event_id' => $commissionEventId,
                    'epc_id' => $epcId,
                    'role' => 'epcList',
                ]);

                DB::table('document_epcs')->insert([
                    'document_id' => $docId,
                    'epc_id' => $epcId,
                    'ingest_generation' => 1,
                ]);
            }

            $rules = app(EpcisCatalogBusinessRules::class);
            $resolver = app(EpcisValidationProfileResolver::class);
            $doc = EpcisDocument::query()->findOrFail($docId);
            $findings = $rules->validate(
                $resolver->resolve($doc, 'inbound'),
                $doc->events()->get(),
            );

            $collection = collect($findings);
            $orphanFindings = $collection->filter(
                fn (EpcisValidationFinding $f): bool => $f->exceptionType === 'ORPHAN_SSCC',
            );

            $this->assertCount(2, $orphanFindings, 'Per-type cap must limit ORPHAN_SSCC findings to max_findings_per_type');
            $this->assertTrue(
                $collection->contains(
                    fn (EpcisValidationFinding $f): bool => $f->exceptionType === 'FINDINGS_TRUNCATED'
                        && str_contains($f->description, 'ORPHAN_SSCC')
                        && str_contains($f->description, '3'),
                ),
                'Truncated ORPHAN_SSCC hits must surface a FINDINGS_TRUNCATED overflow finding',
            );
        } finally {
            config(['tracepharma.epcis.validation.max_findings_per_type' => 50]);

            if ($docId !== null) {
                EpcisException::query()->where('document_id', $docId)->delete();
                DB::table('document_epcs')->where('document_id', $docId)->delete();
                $eventIds = DB::table('epcis_events')->where('document_id', $docId)->pluck('id');
                DB::table('event_epcs')->whereIn('event_id', $eventIds)->delete();
                DB::table('epcis_events')->where('document_id', $docId)->delete();
                DB::table('epcis_documents')->where('id', $docId)->delete();
            }
            foreach ($epcIds as $epcId) {
                if (! DB::table('event_epcs')->where('epc_id', $epcId)->exists()) {
                    DB::table('epcs')->where('id', $epcId)->delete();
                }
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function missing_commissioning_has_critical_severity_and_hard_blocking_receive_impact(): void
    {
        config(['tracepharma.epcis.validation.default_profile' => 'gs1us_r12']);
        config(['tracepharma.epcis.validation.force_r13' => false]);
        config(['tracepharma.epcis.validation.severity_overrides' => []]);

        $document = new EpcisDocument(['direction' => 'inbound']);
        $ctx = app(EpcisValidationProfileResolver::class)->resolve($document, 'inbound');

        $this->assertSame('critical', EpcisValidationSeverityMap::severityFor('MISSING_COMMISSIONING', $ctx));
        $this->assertSame(ExceptionReceiveImpact::HardBlocking, ExceptionReceiveImpactMap::forCode('MISSING_COMMISSIONING'));
    }

    #[Test]
    public function mixed_ndc_type_codes_raise_mixed_guideline_and_preserve_payload_bytes(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$document, $expectedSha, $expectedXml] = $this->ingestMutatedMinimalShipping(
                '</gs1ushc:dscsaTransactionStatement>',
                "</gs1ushc:dscsaTransactionStatement>\n    <!-- "
                .'<attribute id="urn:epcglobal:cbv:mda#additionalTradeItemIdentificationTypeCode">FDA_NDC_11</attribute>'
                .'<attribute id="urn:epcglobal:cbv:mda#additionalTradeItemIdentificationTypeCode">US_FDA_NDC</attribute>'
                .' -->',
            );

            $this->assertMixedGuidelineIngest($document, $expectedSha, $expectedXml);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function boolean_and_qualifier_direct_purchase_raises_mixed_guideline(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$document, $expectedSha, $expectedXml] = $this->ingestMutatedMinimalShipping(
                '</gs1ushc:dscsaTransactionStatement>',
                "</gs1ushc:dscsaTransactionStatement>\n    <!-- "
                .'<gs1ushc:directPurchase>true</gs1ushc:directPurchase>'
                .'<gs1ushc:directPurchase qualifier="ENTIRELY_DIRECT"><gs1ushc:directPurchaseStatement>x</gs1ushc:directPurchaseStatement></gs1ushc:directPurchase>'
                .' -->',
            );

            $this->assertMixedGuidelineIngest($document, $expectedSha, $expectedXml);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function r12_inspecting_bizstep_is_invalid(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$document] = $this->ingestInspectingMinimalShipping(r13: false);

            $open = EpcisException::query()
                ->where('document_id', $document->id)
                ->where('status', 'open')
                ->where('exception_type', 'INVALID_BIZSTEP')
                ->get();

            $this->assertTrue(
                $open->contains(fn (EpcisException $e): bool => str_contains((string) $e->description, 'inspecting')),
                'Expected open INVALID_BIZSTEP for inspecting on R1.2',
            );
            $this->assertSame(
                1,
                $document->events()->where('biz_step', 'urn:epcglobal:cbv:bizstep:inspecting')->count(),
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function r13_inspecting_bizstep_is_allowed(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$document] = $this->ingestInspectingMinimalShipping(r13: true);

            $open = EpcisException::query()
                ->where('document_id', $document->id)
                ->where('status', 'open')
                ->where('exception_type', 'INVALID_BIZSTEP')
                ->get();

            $this->assertFalse(
                $open->contains(fn (EpcisException $e): bool => str_contains((string) $e->description, 'inspecting')),
                'R1.3 inspecting must not raise INVALID_BIZSTEP',
            );
            $this->assertSame(
                1,
                $document->events()->where('biz_step', 'urn:epcglobal:cbv:bizstep:inspecting')->count(),
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function r13_bare_inspecting_bizstep_is_allowed(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$document] = $this->ingestInspectingMinimalShipping(r13: true, bareBizStep: true);

            $open = EpcisException::query()
                ->where('document_id', $document->id)
                ->where('status', 'open')
                ->where('exception_type', 'INVALID_BIZSTEP')
                ->get();

            $this->assertFalse(
                $open->contains(fn (EpcisException $e): bool => str_contains((string) $e->description, 'inspecting')),
                'R1.3 bare inspecting must not raise INVALID_BIZSTEP',
            );
            $this->assertTrue(
                $document->events()->where('biz_step', 'like', '%inspecting%')->exists(),
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function partially_direct_without_indirect_epcs_raises_finding_and_preserves_bytes(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $fixture = base_path('tests/Fixtures/epcis/shipping_direct_purchase_entirely_direct.xml');
            $tmp = tempnam(sys_get_temp_dir(), 'epcis_pd_').'.xml';
            $xml = file_get_contents($fixture);
            $this->assertNotFalse($xml);
            $xml = str_replace('22222222-3333-4444-5555-666666666666', (string) str()->uuid(), $xml);
            $xml = str_replace('qualifier="ENTIRELY_DIRECT"', 'qualifier="PARTIALLY_DIRECT"', $xml);
            file_put_contents($tmp, $xml);
            $expectedSha = hash('sha256', $xml);

            try {
                $document = app(IngestEpcisXmlDocument::class)->handle($tmp, [
                    'direction' => 'inbound',
                    'original_filename' => 'partially-direct-missing-indirect.xml',
                ]);
            } finally {
                @unlink($tmp);
            }

            $this->documentId = (int) $document->getKey();
            $document->refresh();
            $this->assertSame($expectedSha, (string) $document->file_sha256);
            $stored = (string) Storage::disk($document->payload_disk)->get($document->payload_path);
            $this->assertSame($expectedSha, hash('sha256', $stored));
            $this->assertStringNotContainsString('indirectPurchaseEPCs', $stored);

            $open = EpcisException::query()
                ->where('document_id', $document->id)
                ->where('status', 'open')
                ->where('exception_type', 'MISSING_MANDATORY_FIELD')
                ->get();

            $this->assertTrue(
                $open->contains(fn (EpcisException $e): bool => str_contains((string) $e->description, 'PARTIALLY_DIRECT')),
                'Expected open MISSING_MANDATORY_FIELD for PARTIALLY_DIRECT without indirectPurchaseEPCs',
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function event_time_without_timezone_offset_raises_finding_and_does_not_invent_z(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$document, $expectedSha, $expectedXml] = $this->ingestMutatedMinimalShipping(
                '<eventTimeZoneOffset>-05:00</eventTimeZoneOffset>',
                '',
            );

            $this->assertSame($expectedSha, (string) $document->file_sha256);
            $stored = (string) Storage::disk($document->payload_disk)->get($document->payload_path);
            $this->assertSame($expectedXml, $stored);
            $this->assertStringNotContainsString('<eventTimeZoneOffset>', $stored);

            $open = EpcisException::query()
                ->where('document_id', $document->id)
                ->where('status', 'open')
                ->where('exception_type', 'MISSING_MANDATORY_FIELD')
                ->get();

            $this->assertTrue(
                $open->contains(fn (EpcisException $e): bool => str_contains((string) $e->description, 'eventTimeZoneOffset')),
                'Expected open MISSING_MANDATORY_FIELD for missing eventTimeZoneOffset',
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function r12_lot_level_inbound_is_accepted_not_rejected_as_illegal(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
            $tmp = tempnam(sys_get_temp_dir(), 'epcis_lot_').'.xml';
            $xml = file_get_contents($fixture);
            $this->assertNotFalse($xml);
            $xml = str_replace('11111111-2222-3333-4444-555555555555', (string) str()->uuid(), $xml);
            $xml = (string) preg_replace(
                '/<epcList>.*?<\/epcList>/s',
                '<quantityList><quantityElement><epcClass>urn:epc:class:lgtin:030116.0200116.606412T</epcClass><quantity>12</quantity></quantityElement></quantityList>',
                $xml,
            );
            $xml = (string) preg_replace('/<epc>.*?<\/epc>/s', '', $xml);
            $xml = str_replace('<parentID>urn:epc:id:sscc:030116.01001227052</parentID>', '', $xml);
            file_put_contents($tmp, $xml);

            try {
                $document = app(IngestEpcisXmlDocument::class)->handle($tmp, [
                    'direction' => 'inbound',
                    'original_filename' => 'r12-lot-level.xml',
                ]);
            } finally {
                @unlink($tmp);
            }

            $this->documentId = (int) $document->getKey();
            $document->refresh();
            $this->assertNotSame('rejected', $document->status);
            $this->assertContains($document->status, ['parsed', 'validated', 'error', 'pending']);

            $illegal = EpcisException::query()
                ->where('document_id', $document->id)
                ->where('status', 'open')
                ->where('description', 'like', '%lot-level%illegal%')
                ->count();
            $this->assertSame(0, $illegal);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function shipping_biz_location_is_a_finding_and_payload_bytes_stay(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $fixture = base_path('tests/Fixtures/epcis/shipping_direct_purchase_entirely_direct.xml');
            $tmp = tempnam(sys_get_temp_dir(), 'epcis_biz_').'.xml';
            $xml = file_get_contents($fixture);
            $this->assertNotFalse($xml);
            $xml = str_replace('22222222-3333-4444-5555-666666666666', (string) str()->uuid(), $xml);
            $xml = str_replace(
                "        <readPoint>\n          <id>urn:epc:id:sgln:030116.000001.0</id>\n        </readPoint>\n        <bizTransactionList>",
                "        <readPoint>\n          <id>urn:epc:id:sgln:030116.000001.0</id>\n        </readPoint>\n        <bizLocation>\n          <id>urn:epc:id:sgln:030116.000001.0</id>\n        </bizLocation>\n        <bizTransactionList>",
                $xml,
            );
            file_put_contents($tmp, $xml);
            $expectedSha = hash('sha256', $xml);

            try {
                $document = app(IngestEpcisXmlDocument::class)->handle($tmp, [
                    'direction' => 'inbound',
                    'original_filename' => 'shipping-bizlocation.xml',
                ]);
            } finally {
                @unlink($tmp);
            }

            $this->documentId = (int) $document->getKey();
            $document->refresh();
            $this->assertSame($expectedSha, (string) $document->file_sha256);

            $open = EpcisException::query()
                ->where('document_id', $document->id)
                ->where('status', 'open')
                ->get();

            $this->assertTrue(
                $open->contains(fn (EpcisException $e): bool => str_contains((string) $e->description, 'bizLocation')),
                'Expected a shipping bizLocation finding without rewriting XML',
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function r13_abbreviated_net_content_does_not_fail(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $vocab = <<<'XML'
    <extension>
      <EPCISMasterData>
        <VocabularyList>
          <Vocabulary type="urn:epcglobal:epcis:vtype:EPCClass">
            <VocabularyElementList>
              <VocabularyElement id="urn:epc:idpat:sgtin:030116.0200116.*">
                <attribute id="urn:epcglobal:cbv:mda#additionalTradeItemIdentification">00116200116</attribute>
                <attribute id="urn:epcglobal:cbv:mda#additionalTradeItemIdentificationTypeCode">FDA_NDC_11</attribute>
                <attribute id="urn:epcglobal:cbv:mda#netContentDescription">473 mL</attribute>
              </VocabularyElement>
            </VocabularyElementList>
          </Vocabulary>
        </VocabularyList>
      </EPCISMasterData>
    </extension>
XML;

            [$document] = $this->ingestMutatedMinimalShipping(
                '</sbdh:StandardBusinessDocumentHeader>',
                "</sbdh:StandardBusinessDocumentHeader>\n".$vocab
                ."\n    <gs1ushc:guidelineVersion>GS1 US DSCSA R1.3</gs1ushc:guidelineVersion>",
            );

            $netContentFindings = EpcisException::query()
                ->where('document_id', $document->id)
                ->where('status', 'open')
                ->get()
                ->filter(fn (EpcisException $e): bool => str_contains(strtolower((string) $e->description), 'netcontent'));

            $this->assertCount(0, $netContentFindings);

            $class = EpcisDocumentProductClass::query()
                ->where('document_id', $document->id)
                ->where('idpat', 'urn:epc:idpat:sgtin:030116.0200116.*')
                ->first();
            $this->assertNotNull($class);
            $this->assertSame('473 mL', $class->net_content);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function r13_guideline_version_with_fda_ndc_11_is_not_mixed_reject(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $vocab = <<<'XML'
    <extension>
      <EPCISMasterData>
        <VocabularyList>
          <Vocabulary type="urn:epcglobal:epcis:vtype:EPCClass">
            <VocabularyElementList>
              <VocabularyElement id="urn:epc:idpat:sgtin:030116.0200116.*">
                <attribute id="urn:epcglobal:cbv:mda#additionalTradeItemIdentification">00116200116</attribute>
                <attribute id="urn:epcglobal:cbv:mda#additionalTradeItemIdentificationTypeCode">FDA_NDC_11</attribute>
                <attribute id="urn:epcglobal:cbv:mda#manufacturerOfTradeItemPartyName">Xttrium</attribute>
              </VocabularyElement>
            </VocabularyElementList>
          </Vocabulary>
        </VocabularyList>
      </EPCISMasterData>
    </extension>
XML;

            [$document] = $this->ingestMutatedMinimalShipping(
                '</sbdh:StandardBusinessDocumentHeader>',
                "</sbdh:StandardBusinessDocumentHeader>\n".$vocab
                ."\n    <gs1ushc:guidelineVersion>GS1 US DSCSA R1.3</gs1ushc:guidelineVersion>",
            );

            $mixed = EpcisException::query()
                ->where('document_id', $document->id)
                ->where('status', 'open')
                ->where('exception_type', 'MIXED_DSCSA_GUIDELINE_RELEASE')
                ->count();

            $this->assertSame(0, $mixed, 'R1.3 guidelineVersion + transitional FDA_NDC_11 must not be MIXED.');
            $this->assertNotSame('error', $document->fresh()->status, (string) $document->fresh()->error_message);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function item_expiration_1970_sentinel_is_redacted_not_discarded(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
            $this->assertFileExists($fixture);
            $tmp = tempnam(sys_get_temp_dir(), 'epcis_redact_').'.xml';
            $xml = file_get_contents($fixture);
            $this->assertNotFalse($xml);
            $serial = 'R70'.substr(str_replace('-', '', (string) str()->uuid()), 0, 10);
            $sgtin = 'urn:epc:id:sgtin:030116.0200116.'.$serial;
            $this->redactedSgtin = $sgtin;
            $this->purgeSharedEpc($sgtin);
            $xml = str_replace('11111111-2222-3333-4444-555555555555', (string) str()->uuid(), $xml);
            $xml = str_replace(
                'urn:epc:id:sgtin:030116.0200116.10000082001560',
                $sgtin,
                $xml,
            );
            $xml = str_replace(
                '<cbvmda:itemExpirationDate>2029-05-31</cbvmda:itemExpirationDate>',
                '<cbvmda:itemExpirationDate>1970-01-01T00:00:00Z</cbvmda:itemExpirationDate>',
                $xml,
            );
            file_put_contents($tmp, $xml);

            try {
                $document = app(IngestEpcisXmlDocument::class)->handle($tmp, [
                    'direction' => 'inbound',
                    'original_filename' => 'ilmd-1970-sentinel.xml',
                ]);
                $this->documentId = (int) $document->getKey();
            } finally {
                @unlink($tmp);
            }

            $this->assertStringContainsString('1970-01-01T00:00:00Z', $xml);

            $epc = Epc::query()->where('epc_uri', $sgtin)->first();
            $this->assertNotNull($epc);

            $ilmd = EpcIlmd::query()->where('epc_id', $epc->id)->first();
            $this->assertNotNull($ilmd);
            $this->assertSame('606412T', $ilmd->lot_number);
            $this->assertNull($ilmd->expiry_date);
            $this->assertTrue((bool) ($ilmd->extra_json['expiry_redacted'] ?? false));

            $open = EpcisException::query()
                ->where('document_id', $document->id)
                ->where('status', 'open')
                ->whereIn('exception_type', ['MISSING_EXPIRY', 'EXPIRED_PRODUCT_SHIPPED'])
                ->get();
            $this->assertCount(0, $open);

            $stored = (string) Storage::disk($document->payload_disk)->get($document->payload_path);
            $this->assertStringContainsString('1970-01-01T00:00:00Z', $stored);
        } finally {
            $this->cleanup();
        }
    }

    /**
     * @return array{0: EpcisDocument, 1: string, 2: string}
     */
    private function ingestInspectingMinimalShipping(bool $r13, bool $bareBizStep = false): array
    {
        $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
        $this->assertFileExists($fixture);

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_insp_').'.xml';
        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);
        $xml = str_replace('11111111-2222-3333-4444-555555555555', (string) str()->uuid(), $xml);

        if ($r13) {
            $xml = str_replace(
                '</gs1ushc:dscsaTransactionStatement>',
                "</gs1ushc:dscsaTransactionStatement>\n    <gs1ushc:guidelineVersion>GS1 US DSCSA R1.3</gs1ushc:guidelineVersion>",
                $xml,
            );
        }

        $bizStep = $bareBizStep
            ? 'inspecting'
            : 'urn:epcglobal:cbv:bizstep:inspecting';
        $inspectingEvent = <<<XML
      <ObjectEvent>
        <eventTime>2026-06-18T23:30:00.000Z</eventTime>
        <eventTimeZoneOffset>-05:00</eventTimeZoneOffset>
        <epcList>
          <epc>urn:epc:id:sgtin:030116.0200116.INSPECT001</epc>
        </epcList>
        <action>OBSERVE</action>
        <bizStep>{$bizStep}</bizStep>
        <disposition>urn:epcglobal:cbv:disp:active</disposition>
        <readPoint>
          <id>urn:epc:id:sgln:030116.000000.0</id>
        </readPoint>
        <bizLocation>
          <id>urn:epc:id:sgln:030116.000000.0</id>
        </bizLocation>
      </ObjectEvent>
XML;
        $xml = str_replace('<EventList>', "<EventList>\n".$inspectingEvent, $xml);
        file_put_contents($tmp, $xml);

        try {
            $document = app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => $r13 ? 'inspecting-r13.xml' : 'inspecting-r12.xml',
            ]);
            $this->documentId = (int) $document->getKey();

            return [$document, hash('sha256', $xml), $xml];
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * @return array{0: EpcisDocument, 1: string, 2: string}
     */
    private function ingestMutatedMinimalShipping(string $search, string $replace): array
    {
        $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
        $this->assertFileExists($fixture);

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_mix_').'.xml';
        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);
        $xml = str_replace('11111111-2222-3333-4444-555555555555', (string) str()->uuid(), $xml);
        $xml = str_replace($search, $replace, $xml);
        file_put_contents($tmp, $xml);

        $expectedSha = hash('sha256', $xml);

        try {
            $document = app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'mixed-dscsa-guideline.xml',
            ]);
            $this->documentId = (int) $document->getKey();

            return [$document, $expectedSha, $xml];
        } finally {
            @unlink($tmp);
        }
    }

    private function assertMixedGuidelineIngest(EpcisDocument $document, string $expectedSha, string $expectedXml): void
    {
        $document->refresh();

        $this->assertSame('error', $document->status);
        $this->assertNull($document->dscsa_guideline_release);
        $this->assertSame($expectedSha, (string) $document->file_sha256);

        $stored = (string) Storage::disk($document->payload_disk)->get($document->payload_path);
        $this->assertSame($expectedXml, $stored);
        $this->assertSame($expectedSha, hash('sha256', $stored));

        $open = EpcisException::query()
            ->where('document_id', $document->id)
            ->where('status', 'open')
            ->where('exception_type', 'MIXED_DSCSA_GUIDELINE_RELEASE')
            ->get();

        $this->assertCount(1, $open);
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
            EpcisException::query()->where('document_id', $this->documentId)->delete();
            EpcisDocument::query()->whereKey($this->documentId)->delete();
            $this->documentId = null;
        }

        foreach ([
            self::SSCC_URI,
            'urn:epc:id:sgtin:030116.0200116.10000082001560',
            'urn:epc:id:sgtin:030116.0200116.INSPECT001',
            'urn:epc:id:sgtin:030116.0200116.REDACT1970001',
            'urn:epc:id:sscc:030116.01001227052',
            $this->redactedSgtin,
        ] as $uri) {
            if (! is_string($uri) || $uri === '') {
                continue;
            }
            $epc = Epc::query()->where('epc_uri', $uri)->first();
            if ($epc !== null && ! DB::table('event_epcs')->where('epc_id', $epc->id)->exists()) {
                $this->purgeSharedEpc($uri);
            }
        }

        $this->redactedSgtin = null;

        tenancy()->end();
    }

    private function purgeSharedEpc(string $uri): void
    {
        $epc = Epc::query()->where('epc_uri', $uri)->first();
        if ($epc === null) {
            return;
        }

        DB::table('aggregation_links')
            ->where('parent_epc_id', $epc->id)
            ->orWhere('child_epc_id', $epc->id)
            ->delete();

        if (Schema::hasTable('event_epc_ilmd')) {
            DB::table('event_epc_ilmd')->where('epc_id', $epc->id)->delete();
        }

        EpcIlmd::query()->where('epc_id', $epc->id)->delete();

        $epc->delete();
    }
}
