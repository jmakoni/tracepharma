<?php

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Epcis\ValidateEpcis12Document;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisException;
use App\Models\Tenant;
use App\Support\Epcis\Validation\EpcisCatalogBusinessRules;
use App\Support\Epcis\Validation\EpcisValidationFinding;
use App\Support\Epcis\Validation\EpcisValidationProfileResolver;
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
            'urn:epc:id:sscc:030116.01001227052',
        ] as $uri) {
            $epc = Epc::query()->where('epc_uri', $uri)->first();
            if ($epc !== null && ! DB::table('event_epcs')->where('epc_id', $epc->id)->exists()) {
                $epc->delete();
            }
        }

        tenancy()->end();
    }
}
