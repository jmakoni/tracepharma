<?php

namespace Tests\Feature\Shipping;

use App\Enums\TenantProfile;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisDocumentProductClass;
use App\Models\Tenant;
use App\Support\Epcis\BuildFullHistoryShippingEpcisXml;
use App\Support\Gs1\Gtin;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * EPCClass master data we author is read verbatim by partners, so it may only come
 * from the document being shipped — and, within it, from the trade item actually on
 * the pallet rather than a richer-looking sibling packaging level.
 */
class ShippingEpcisProductClassScopeTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const COMPANY_PREFIX = '030116';

    private const ITEM_REFERENCE = '402316';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $documentIds = [];

    #[Test]
    public function master_data_from_another_document_is_never_used(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $otherDocument = $this->makeDocument();
            $shippedDocument = $this->makeDocument();

            $this->makeProductClass($otherDocument, '0', [
                'name' => 'Foreign Document Product',
                'strength' => 'FOREIGN-STRENGTH',
                'manufacturer' => 'Foreign Labs',
            ]);

            $xml = $this->epcClassVocabularyXml('0', (int) $shippedDocument->getKey());

            $this->assertStringNotContainsString('Foreign Document Product', $xml);
            $this->assertStringNotContainsString('FOREIGN-STRENGTH', $xml);
            $this->assertStringNotContainsString('Foreign Labs', $xml);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function an_exact_match_wins_over_a_richer_case_level_sibling(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = $this->makeDocument();

            $this->makeProductClass($document, '3', [
                'name' => 'Case Master Name',
                'strength' => 'CASE-STRENGTH',
                'net_content' => '10 BOTTLE in 1 CASE',
                'manufacturer' => 'Case Labs',
            ]);
            $this->makeProductClass($document, '0', [
                'name' => 'Unit Master Name',
            ]);

            $xml = $this->epcClassVocabularyXml('0', (int) $document->getKey());

            $this->assertStringContainsString('Unit Master Name', $xml);
            $this->assertStringNotContainsString('Case Master Name', $xml);
            $this->assertStringNotContainsString('CASE-STRENGTH', $xml);
            $this->assertStringNotContainsString('10 BOTTLE in 1 CASE', $xml);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function a_sibling_packaging_level_still_fills_in_when_the_item_declared_nothing(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = $this->makeDocument();

            $this->makeProductClass($document, '3', [
                'name' => 'Case Master Name',
                'strength' => 'CASE-STRENGTH',
            ]);

            $xml = $this->epcClassVocabularyXml('0', (int) $document->getKey());

            $this->assertStringContainsString('CASE-STRENGTH', $xml);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function a_generation_that_was_superseded_is_not_used(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = $this->makeDocument();

            $this->makeProductClass($document, '0', [
                'name' => 'Superseded Product',
                'strength' => 'OLD-STRENGTH',
            ], ingestGeneration: 1);

            $xml = $this->epcClassVocabularyXml('0', (int) $document->getKey(), ingestGeneration: 2);

            $this->assertStringNotContainsString('Superseded Product', $xml);
            $this->assertStringNotContainsString('OLD-STRENGTH', $xml);
        } finally {
            $this->cleanup();
        }
    }

    private function epcClassVocabularyXml(
        string $indicatorDigit,
        ?int $documentId,
        int $ingestGeneration = 1,
    ): string {
        $method = new ReflectionMethod(BuildFullHistoryShippingEpcisXml::class, 'epcClassVocabularyXml');

        $parsed = $this->parsedFor($indicatorDigit);

        return (string) $method->invoke(
            app(BuildFullHistoryShippingEpcisXml::class),
            [$parsed['company_prefix'].'.'.$parsed['indicator_digit'].$parsed['item_reference'] => $parsed],
            $documentId,
            $ingestGeneration,
        );
    }

    /**
     * @return array{company_prefix: string, indicator_digit: string, item_reference: string, gtin14: string}
     */
    private function parsedFor(string $indicatorDigit): array
    {
        $body = $indicatorDigit.self::COMPANY_PREFIX.self::ITEM_REFERENCE;

        return [
            'company_prefix' => self::COMPANY_PREFIX,
            'indicator_digit' => $indicatorDigit,
            'item_reference' => self::ITEM_REFERENCE,
            'gtin14' => $body.Gtin::checkDigit($body),
        ];
    }

    /**
     * @param  array<string, string>  $attributes
     */
    private function makeProductClass(
        EpcisDocument $document,
        string $indicatorDigit,
        array $attributes,
        int $ingestGeneration = 1,
    ): EpcisDocumentProductClass {
        $parsed = $this->parsedFor($indicatorDigit);

        return EpcisDocumentProductClass::query()->create(array_merge([
            'document_id' => $document->getKey(),
            'ingest_generation' => $ingestGeneration,
            'idpat' => 'urn:epc:idpat:sgtin:'.$parsed['company_prefix'].'.'.$indicatorDigit.$parsed['item_reference'].'.*',
            'gtin14' => $parsed['gtin14'],
            'attributes_json' => [],
        ], $attributes));
    }

    private function makeDocument(): EpcisDocument
    {
        $path = 'epcis/inbound/class-scope-'.(string) str()->uuid().'.xml';
        Storage::disk('local')->put($path, '<epcis/>');

        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) str()->uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'direction' => 'inbound',
            'format' => 'xml',
            'original_filename' => 'class-scope.xml',
            'file_sha256' => hash('sha256', (string) str()->uuid()),
            'payload_disk' => 'local',
            'payload_path' => $path,
            'dscsa_affirm' => false,
            'status' => 'validated',
            'event_count' => 0,
            'epc_count' => 0,
            'received_at' => now(),
            'ingest_generation' => 1,
            'reprocess_count' => 0,
        ]);

        $this->documentIds[] = (int) $document->getKey();

        return $document;
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

        if ($this->documentIds !== []) {
            EpcisDocumentProductClass::query()->whereIn('document_id', $this->documentIds)->delete();
            EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            $this->documentIds = [];
        }

        tenancy()->end();
    }
}
