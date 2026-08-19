<?php

namespace Tests\Feature\Exceptions;

use App\Enums\ExceptionSeverity;
use App\Enums\ExceptionStatus;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionType;
use App\Models\Tenant;
use App\Notifications\DscsaExceptionSupplierMail;
use App\Notifications\ExceptionCreated;
use App\Support\Exceptions\ExceptionEmailContextBuilder;
use Database\Seeders\ExceptionCaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExceptionEmailContextBuilderTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?int $caseId = null;

    private ?int $documentId = null;

    /** @var list<int> */
    private array $epcIds = [];

    #[Test]
    public function builder_fills_po_asn_sscc_and_ts_citation_from_the_case(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $case = $this->missingTsCase();
            $context = app(ExceptionEmailContextBuilder::class)->build($case);

            $this->assertSame('PO-9911', $context['po_number']);
            $this->assertSame('ASN-4422', $context['asn_number']);
            $this->assertNotSame('', $context['sscc']);
            $this->assertSame('DSCSA §582.1(a)(6) Transaction Statement (TS)', $context['dscsa_section']);
            $this->assertTrue($context['compliance_hold']);
            $this->assertContains('Awaiting corrected TI/TS or re-transmitted EPCIS from supplier', $context['receiver_actions']);

            $subject = app(ExceptionEmailContextBuilder::class)->subject($context);
            $this->assertStringContainsString('PO PO-9911', $subject);
            $this->assertStringContainsString('ASN ASN-4422', $subject);
            $this->assertStringContainsString('SSCC '.$context['sscc'], $subject);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function created_mail_subject_includes_purchase_order(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $case = $this->missingTsCase();
            $mail = (new ExceptionCreated($case))->toMail((object) ['email' => 'owner@demo.test']);

            $this->assertStringContainsString('PO PO-9911', (string) $mail->subject);
            $intro = implode("\n", array_map(
                fn (mixed $line): string => is_array($line) ? implode(' ', $line) : (string) $line,
                $mail->introLines,
            ));
            $this->assertStringContainsString('Awaiting corrected TI/TS or re-transmitted EPCIS from supplier', $intro);
            $this->assertStringContainsString('Shipment placed on COMPLIANCE HOLD — not Ready to receive', $intro);
            $this->assertStringNotContainsString('Inbound queue: Compliance hold', $intro);
            $this->assertStringContainsString('https://'.self::DEMO2_DOMAIN.'/exceptions/'.$case->getKey(), (string) $mail->actionUrl);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function builder_uses_document_sscc_when_the_case_only_has_sgtins(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $type = ExceptionType::query()->where('code', 'MISSING_DSCSA_STATEMENT')->firstOrFail();

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'direction' => 'inbound',
                'creation_date' => now(),
                'received_at' => now(),
                'customer_po' => 'PO-DOC-SSCC',
                'original_filename' => 'ship.xml',
            ]);
            $this->documentId = (int) $document->getKey();

            $sgtin = Epc::fromUri('urn:epc:id:sgtin:030116.0200116.ssccfallback01');
            $sgtin->first_seen_at = now();
            $sgtin->save();
            $this->epcIds[] = (int) $sgtin->getKey();

            $sscc = Epc::fromUri('urn:epc:id:sscc:030116.00000210888');
            $sscc->first_seen_at = now();
            $sscc->save();
            $this->epcIds[] = (int) $sscc->getKey();

            $this->assertTrue(Schema::hasTable('document_epcs'));
            DB::table('document_epcs')->insert([
                'document_id' => $document->getKey(),
                'epc_id' => $sscc->getKey(),
                'ingest_generation' => (int) ($document->ingest_generation ?? 1),
            ]);

            $case = ExceptionCase::query()->create([
                'exception_type_id' => $type->getKey(),
                'document_id' => $document->getKey(),
                'title' => 'Missing transaction statement',
                'description' => 'affirmTransactionStatement missing',
                'severity' => ExceptionSeverity::High,
                'status' => ExceptionStatus::New,
            ]);
            $case->epcs()->attach($sgtin->getKey());
            $this->caseId = (int) $case->getKey();

            $fresh = $case->fresh(['type', 'document']);
            $this->assertNotNull($fresh);
            $this->assertFalse($fresh->relationLoaded('epcs'));

            $context = app(ExceptionEmailContextBuilder::class)->build($fresh);

            $this->assertFalse($fresh->relationLoaded('epcs'));
            $this->assertSame((string) $sscc->sscc18, $context['sscc']);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function supplier_mail_subject_includes_po_and_asn(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $case = $this->missingTsCase();
            $mail = (new DscsaExceptionSupplierMail($case, 'https://example.test/portal'))
                ->toMail((object) ['email' => 'supplier@example.test']);

            $this->assertStringContainsString('PO PO-9911', (string) $mail->subject);
            $this->assertStringContainsString('ASN ASN-4422', (string) $mail->subject);
        } finally {
            $this->cleanup();
        }
    }

    private function missingTsCase(): ExceptionCase
    {
        $type = ExceptionType::query()->where('code', 'MISSING_DSCSA_STATEMENT')->firstOrFail();

        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) str()->uuid(),
            'direction' => 'inbound',
            'creation_date' => now(),
            'received_at' => now(),
            'customer_po' => 'PO-9911',
            'asn_number' => 'ASN-4422',
            'original_filename' => 'ship.xml',
        ]);
        $this->documentId = (int) $document->getKey();

        $epc = Epc::fromUri('urn:epc:id:sscc:030116.00000210167');
        $epc->first_seen_at = now();
        $epc->save();
        $this->epcIds[] = (int) $epc->getKey();

        $case = ExceptionCase::query()->create([
            'exception_type_id' => $type->getKey(),
            'document_id' => $document->getKey(),
            'title' => 'Missing transaction statement',
            'description' => 'affirmTransactionStatement missing',
            'severity' => ExceptionSeverity::High,
            'status' => ExceptionStatus::New,
        ]);
        $case->epcs()->attach($epc->getKey());
        $this->caseId = (int) $case->getKey();

        return $case->fresh(['type', 'document']) ?? $case;
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
            $tenant->domains()->firstOrCreate(['domain' => self::DEMO2_DOMAIN]);
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

        if ($this->caseId !== null) {
            $case = ExceptionCase::query()->find($this->caseId);
            $case?->epcs()->detach();
            $case?->activities()->delete();
            $case?->delete();
        }

        if ($this->epcIds !== []) {
            if (Schema::hasTable('document_epcs')) {
                DB::table('document_epcs')->whereIn('epc_id', $this->epcIds)->delete();
            }
            Epc::query()->whereIn('id', $this->epcIds)->delete();
        }

        if ($this->documentId !== null) {
            EpcisDocument::query()->whereKey($this->documentId)->delete();
        }

        $this->caseId = null;
        $this->epcIds = [];
        $this->documentId = null;
        tenancy()->end();
    }
}
