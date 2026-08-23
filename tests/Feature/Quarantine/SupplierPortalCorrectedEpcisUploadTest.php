<?php

namespace Tests\Feature\Quarantine;

use App\Enums\EpcisJobKind;
use App\Enums\EpcisReceivedVia;
use App\Enums\ExceptionActivityKind;
use App\Enums\ExceptionActivityVisibility;
use App\Enums\ExceptionReceiveImpact;
use App\Enums\ExceptionSeverity;
use App\Enums\ExceptionStatus;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisException;
use App\Models\EpcisJob;
use App\Models\Exceptions\ExceptionActivity;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionType;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Services\Exceptions\ExceptionService;
use App\Services\Quarantine\QuarantineService;
use App\Services\Quarantine\SupplierPortalService;
use Database\Seeders\ExceptionCaseSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SupplierPortalCorrectedEpcisUploadTest extends TestCase
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
    private array $partnerIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $jobIds = [];

    #[Test]
    public function signed_partner_upload_creates_new_inbound_document_and_job(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            config([
                'tracepharma.epcis_jobs.enabled' => true,
                'tracepharma.epcis_jobs.queue' => 'epcis',
                'queue.default' => 'database',
            ]);
            Queue::fake();

            $partner = $this->createPartner('Correction Upload Partner');
            app(SupplierPortalService::class)->ensurePartnerPortalLink($partner);

            $document = $this->makeInboundDocument($partner);
            $this->attachIdentifierEpcs($document);
            $case = $this->makeDocumentScopedBlockingCase($document, $partner, 'UNKNOWN_GTIN');

            URL::forceRootUrl('http://'.self::DEMO2_DOMAIN);
            $showUrl = app(QuarantineService::class)->signedSupplierUrl($case->fresh());
            $case->refresh();
            $uploadUrl = URL::temporarySignedRoute(
                'tenant.supplier-quarantine.upload',
                now()->addDays(30),
                ['shareUuid' => $case->share_uuid],
            );

            $documentsBefore = EpcisDocument::query()->count();
            $xml = $this->uniqueFixtureXml();
            $file = UploadedFile::fake()->createWithContent('corrected-1.2.xml', $xml);

            tenancy()->end();

            $this->get($showUrl)
                ->assertOk()
                ->assertSee('Unknown / Unregistered GTIN', false)
                ->assertSee('2 GTINs', false)
                ->assertSee('2 SSCCs', false)
                ->assertSee('Upload corrected EPCIS', false);

            $response = $this->post($uploadUrl, ['file' => $file]);
            $response->assertRedirect();
            $response->assertSessionHas('status');

            tenancy()->initialize($tenant);

            $created = EpcisDocument::query()
                ->where('trading_partner_id', $partner->getKey())
                ->where('direction', 'inbound')
                ->whereKeyNot($document->getKey())
                ->latest('id')
                ->first();

            $this->assertNotNull($created);
            $this->assertNotSame((int) $document->getKey(), (int) $created->getKey());
            $this->assertSame((int) $partner->getKey(), (int) $created->trading_partner_id);
            $this->assertSame('inbound', $created->direction);
            $this->assertSame(EpcisReceivedVia::FilamentUpload, $created->received_via);
            $this->assertSame('corrected-1.2.xml', $created->original_filename);
            $this->assertSame($documentsBefore + 1, EpcisDocument::query()->count());
            $this->documentIds[] = (int) $created->getKey();

            $job = EpcisJob::query()
                ->where('epcis_document_id', $created->getKey())
                ->where('kind', EpcisJobKind::InboundProcess->value)
                ->first();

            $this->assertNotNull($job);
            $this->jobIds[] = (int) $job->getKey();

            $activity = ExceptionActivity::query()
                ->where('exception_id', $case->getKey())
                ->where('visibility', ExceptionActivityVisibility::Partner->value)
                ->where('kind', ExceptionActivityKind::Comment->value)
                ->latest('id')
                ->first();

            $this->assertNotNull($activity);
            $this->assertStringContainsString('corrected-1.2.xml', (string) $activity->body);
            $this->assertSame('supplier_quarantine_page', $activity->meta['source'] ?? null);
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function different_partner_or_unsigned_url_is_forbidden_and_creates_no_document(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            config([
                'tracepharma.epcis_jobs.enabled' => true,
                'queue.default' => 'database',
            ]);
            Queue::fake();

            $partnerA = $this->createPartner('Owner Partner');
            $partnerB = $this->createPartner('Other Partner');
            app(SupplierPortalService::class)->ensurePartnerPortalLink($partnerA);
            app(SupplierPortalService::class)->ensurePartnerPortalLink($partnerB);

            $document = $this->makeInboundDocument($partnerA);
            $caseA = $this->makeDocumentScopedBlockingCase($document, $partnerA, 'INGESTION_PARSE_ERROR');
            $caseB = $this->makeDocumentScopedBlockingCase(
                $this->makeInboundDocument($partnerB),
                $partnerB,
                'UNKNOWN_GTIN',
            );

            URL::forceRootUrl('http://'.self::DEMO2_DOMAIN);
            app(QuarantineService::class)->ensureShareLink($caseA->fresh());
            app(QuarantineService::class)->ensureShareLink($caseB->fresh());
            $caseA->refresh();
            $caseB->refresh();

            $unsignedUrl = 'http://'.self::DEMO2_DOMAIN.'/supplier-quarantine/'.$caseA->share_uuid.'/upload';
            $signedForB = URL::temporarySignedRoute(
                'tenant.supplier-quarantine.upload',
                now()->addDays(30),
                ['shareUuid' => $caseB->share_uuid],
            );
            $wrongPartnerUrl = 'http://'.self::DEMO2_DOMAIN.'/supplier-quarantine/'.$caseA->share_uuid.'/upload?'.parse_url($signedForB, PHP_URL_QUERY);

            $documentsBefore = EpcisDocument::query()->count();
            $xml = $this->uniqueFixtureXml();

            tenancy()->end();

            $this->post($unsignedUrl, [
                'file' => UploadedFile::fake()->createWithContent('corrected-1.2.xml', $xml),
            ])->assertForbidden();

            $this->post($wrongPartnerUrl, [
                'file' => UploadedFile::fake()->createWithContent('corrected-1.2.xml', $xml),
            ])->assertForbidden();

            tenancy()->initialize($tenant);

            $this->assertSame($documentsBefore, EpcisDocument::query()->count());
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function epcis_1_0_upload_is_rejected_before_ingest(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            config([
                'tracepharma.epcis_jobs.enabled' => true,
                'queue.default' => 'database',
            ]);
            Queue::fake();

            $partner = $this->createPartner('Schema Reject Partner');
            app(SupplierPortalService::class)->ensurePartnerPortalLink($partner);

            $document = $this->makeInboundDocument($partner);
            $case = $this->makeDocumentScopedBlockingCase($document, $partner, 'INGESTION_PARSE_ERROR');

            URL::forceRootUrl('http://'.self::DEMO2_DOMAIN);
            app(QuarantineService::class)->ensureShareLink($case->fresh());
            $case->refresh();

            $uploadUrl = URL::temporarySignedRoute(
                'tenant.supplier-quarantine.upload',
                now()->addDays(30),
                ['shareUuid' => $case->share_uuid],
            );

            $documentsBefore = EpcisDocument::query()->count();
            $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<epcis:EPCISDocument xmlns:epcis="urn:epcglobal:epcis:xsd:1" schemaVersion="1.0" creationDate="2026-08-09T12:00:00.000Z">
</epcis:EPCISDocument>
XML;

            tenancy()->end();

            $response = $this->post($uploadUrl, [
                'file' => UploadedFile::fake()->createWithContent('legacy-1.0.xml', $xml),
            ]);

            $this->assertTrue(
                $response->status() === 422 || $response->isRedirect(),
                'EPCIS 1.0 must be rejected with 422 or a form error redirect, got '.$response->status(),
            );
            if ($response->isRedirect()) {
                $response->assertSessionHasErrors('file');
                $errors = session('errors');
                $this->assertNotNull($errors);
                $fileError = (string) $errors->first('file');
                $this->assertStringNotContainsString(sys_get_temp_dir(), $fileError);
                $this->assertStringNotContainsString('/tmp/', $fileError);
                $this->assertStringNotContainsString('epcis_supplier_', $fileError);
            } else {
                $response->assertSee('1.2', false);
            }

            tenancy()->initialize($tenant);

            $this->assertSame($documentsBefore, EpcisDocument::query()->count());
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function signed_partner_upload_accepts_epcis_1_3(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            config([
                'tracepharma.epcis_jobs.enabled' => true,
                'tracepharma.epcis_jobs.queue' => 'epcis',
                'queue.default' => 'database',
            ]);
            Queue::fake();

            $partner = $this->createPartner('Schema 1.3 Partner');
            app(SupplierPortalService::class)->ensurePartnerPortalLink($partner);

            $document = $this->makeInboundDocument($partner);
            $case = $this->makeDocumentScopedBlockingCase($document, $partner, 'INGESTION_PARSE_ERROR');

            URL::forceRootUrl('http://'.self::DEMO2_DOMAIN);
            app(QuarantineService::class)->ensureShareLink($case->fresh());
            $case->refresh();

            $uploadUrl = URL::temporarySignedRoute(
                'tenant.supplier-quarantine.upload',
                now()->addDays(30),
                ['shareUuid' => $case->share_uuid],
            );

            $xml = str_replace('schemaVersion="1.2"', 'schemaVersion="1.3"', $this->uniqueFixtureXml());

            tenancy()->end();

            $response = $this->post($uploadUrl, [
                'file' => UploadedFile::fake()->createWithContent('corrected-1.3.xml', $xml),
            ]);
            $response->assertRedirect();
            $response->assertSessionHas('status');

            tenancy()->initialize($tenant);

            $created = EpcisDocument::query()
                ->where('trading_partner_id', $partner->getKey())
                ->where('direction', 'inbound')
                ->whereKeyNot($document->getKey())
                ->latest('id')
                ->first();

            $this->assertNotNull($created);
            $this->documentIds[] = (int) $created->getKey();
            $this->assertSame('1.3', $created->schema_version);
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function duplicate_upload_flashes_generic_already_received_copy(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            config([
                'tracepharma.epcis_jobs.enabled' => true,
                'tracepharma.epcis_jobs.queue' => 'epcis',
                'queue.default' => 'database',
            ]);
            Queue::fake();

            $partner = $this->createPartner('Duplicate Upload Partner');
            app(SupplierPortalService::class)->ensurePartnerPortalLink($partner);

            $document = $this->makeInboundDocument($partner);
            $case = $this->makeDocumentScopedBlockingCase($document, $partner, 'INGESTION_PARSE_ERROR');

            URL::forceRootUrl('http://'.self::DEMO2_DOMAIN);
            app(QuarantineService::class)->ensureShareLink($case->fresh());
            $case->refresh();

            $uploadUrl = URL::temporarySignedRoute(
                'tenant.supplier-quarantine.upload',
                now()->addDays(30),
                ['shareUuid' => $case->share_uuid],
            );

            $xml = $this->uniqueFixtureXml();
            $file = UploadedFile::fake()->createWithContent('corrected-1.2.xml', $xml);

            tenancy()->end();

            $this->post($uploadUrl, ['file' => $file])->assertRedirect()->assertSessionHas('status');

            tenancy()->initialize($tenant);
            $created = EpcisDocument::query()
                ->where('trading_partner_id', $partner->getKey())
                ->where('direction', 'inbound')
                ->whereKeyNot($document->getKey())
                ->latest('id')
                ->first();
            $this->assertNotNull($created);
            $this->documentIds[] = (int) $created->getKey();
            $job = EpcisJob::query()->where('epcis_document_id', $created->getKey())->first();
            if ($job !== null) {
                $this->jobIds[] = (int) $job->getKey();
            }
            tenancy()->end();

            $duplicate = $this->post($uploadUrl, [
                'file' => UploadedFile::fake()->createWithContent('corrected-1.2.xml', $xml),
            ]);
            $duplicate->assertRedirect();
            $duplicate->assertSessionHasErrors('file');

            $fileError = (string) session('errors')?->first('file');
            $this->assertSame('This file was already received. Upload a different corrected EPCIS file.', $fileError);
            $this->assertStringNotContainsString('#', $fileError);
            $this->assertStringNotContainsString(sys_get_temp_dir(), $fileError);
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
        }
    }

    private function createPartner(string $name): TradingPartner
    {
        $partner = TradingPartner::query()->create([
            'name' => $name.' '.substr((string) str()->uuid(), 0, 8),
            'partner_type' => 'manufacturer',
            'is_active' => true,
        ]);
        $this->partnerIds[] = (int) $partner->getKey();

        return $partner;
    }

    private function makeInboundDocument(TradingPartner $partner): EpcisDocument
    {
        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) str()->uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'direction' => 'inbound',
            'trading_partner_id' => $partner->getKey(),
            'format' => 'xml',
            'original_filename' => 'blocked-inbound.xml',
            'file_sha256' => hash('sha256', (string) str()->uuid()),
            'payload_disk' => 'local',
            'payload_path' => 'epcis/inbound/supplier-correction-'.str()->uuid().'.xml',
            'dscsa_affirm' => false,
            'status' => 'error',
            'event_count' => 0,
            'epc_count' => 0,
            'received_at' => now(),
            'ingest_generation' => 1,
        ]);
        $this->documentIds[] = (int) $document->getKey();

        return $document;
    }

    private function attachIdentifierEpcs(EpcisDocument $document): void
    {
        $gtinA = '00301161111114';
        $gtinB = '00301162222221';
        $ids = [
            $this->makeSgtin($gtinA, 'a'.substr((string) str()->uuid(), 0, 6)),
            $this->makeSgtin($gtinB, 'b'.substr((string) str()->uuid(), 0, 6)),
            $this->makeSscc('003011611111111111'),
            $this->makeSscc('003011622222222228'),
        ];

        $this->assertTrue(Schema::hasTable('document_epcs'));

        foreach ($ids as $epcId) {
            DB::table('document_epcs')->insert([
                'document_id' => $document->getKey(),
                'epc_id' => $epcId,
                'ingest_generation' => (int) ($document->ingest_generation ?? 1),
            ]);
        }

        $document->forceFill(['epc_count' => count($ids)])->save();
    }

    private function makeSgtin(string $gtin14, string $serial): int
    {
        $epc = Epc::query()->create([
            'epc_type' => 'sgtin',
            'epc_uri' => 'urn:epc:id:sgtin:030116.0'.substr($gtin14, -6).'.'.$serial,
            'gtin14' => $gtin14,
            'serial_number' => $serial,
            'company_prefix' => '030116',
            'first_seen_at' => now(),
        ]);
        $this->epcIds[] = (int) $epc->id;

        return (int) $epc->id;
    }

    private function makeSscc(string $sscc18): int
    {
        $epc = Epc::query()->create([
            'epc_type' => 'sscc',
            'epc_uri' => 'urn:epc:id:sscc:030116.'.substr($sscc18, -11),
            'sscc18' => $sscc18,
            'company_prefix' => '030116',
            'first_seen_at' => now(),
        ]);
        $this->epcIds[] = (int) $epc->id;

        return (int) $epc->id;
    }

    private function makeDocumentScopedBlockingCase(
        EpcisDocument $document,
        TradingPartner $partner,
        string $typeCode,
    ): ExceptionCase {
        $type = ExceptionType::query()->where('code', $typeCode)->where('is_active', true)->firstOrFail();
        $type->forceFill([
            'receive_impact' => $typeCode === 'INGESTION_PARSE_ERROR'
                ? ExceptionReceiveImpact::HardBlocking
                : ExceptionReceiveImpact::BusinessRule,
        ])->save();

        $case = app(ExceptionService::class)->create([
            'exception_type_id' => $type->getKey(),
            'document_id' => $document->getKey(),
            'trading_partner_id' => $partner->getKey(),
            'title' => $type->name.' '.$document->getKey(),
            'description' => $type->name,
            'severity' => ExceptionSeverity::High->value,
            'status' => ExceptionStatus::New->value,
        ]);
        $this->caseIds[] = (int) $case->getKey();

        EpcisException::query()->create([
            'document_id' => $document->getKey(),
            'case_id' => $case->getKey(),
            'exception_type' => $typeCode,
            'severity' => 'error',
            'description' => $type->name,
            'status' => 'open',
        ]);

        $this->assertTrue($case->fresh(['epcs', 'quarantineHolds'])->isDocumentScoped());
        $this->assertTrue($type->fresh()->blocksReceiving());

        return $case->fresh(['type', 'tradingPartner', 'document']) ?? $case;
    }

    private function uniqueFixtureXml(): string
    {
        $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
        $this->assertFileExists($fixture);
        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);

        return str_replace('11111111-2222-3333-4444-555555555555', (string) str()->uuid(), $xml);
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

        foreach ($this->jobIds as $id) {
            EpcisJob::query()->whereKey($id)->delete();
        }
        $this->jobIds = [];

        foreach ($this->caseIds as $caseId) {
            $case = ExceptionCase::query()->find($caseId);
            if ($case === null) {
                continue;
            }

            $case->activities()->delete();
            QuarantineHold::query()->where('exception_id', $caseId)->delete();
            EpcisException::query()->where('case_id', $caseId)->delete();
            $case->epcs()->detach();
            $case->delete();
        }
        $this->caseIds = [];

        foreach ($this->documentIds as $id) {
            EpcisException::query()->where('document_id', $id)->delete();
            EpcisJob::query()->where('epcis_document_id', $id)->delete();
            if (Schema::hasTable('document_epcs')) {
                DB::table('document_epcs')->where('document_id', $id)->delete();
            }
            $document = EpcisDocument::query()->find($id);
            if ($document !== null && filled($document->payload_path)) {
                try {
                    Storage::disk((string) $document->payload_disk)->delete((string) $document->payload_path);
                } catch (\Throwable) {
                }
            }
            EpcisDocument::query()->whereKey($id)->delete();
        }
        $this->documentIds = [];

        foreach ($this->epcIds as $id) {
            QuarantineHold::query()->where('epc_id', $id)->delete();
            Epc::query()->whereKey($id)->delete();
        }
        $this->epcIds = [];

        foreach ($this->partnerIds as $id) {
            TradingPartner::query()->whereKey($id)->update(['portal_share_uuid' => null]);
            TradingPartner::query()->whereKey($id)->delete();
        }
        $this->partnerIds = [];

        tenancy()->end();
    }
}
