<?php

declare(strict_types=1);

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\ReceiveEpcisUpload;
use App\Enums\TenantProfile;
use App\Models\Epcis\EpcisDocument;
use App\Models\Tenant;
use App\Services\Epcis\EpcisIngestionService;
use App\Services\Integrations\InboundPayloadResolver;
use App\Support\Epcis\EpcisSoapDocumentNormalizer;
use App\Support\Epcis\Validation\EpcisXsdValidator;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SoapUnwrapIngestTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $documentIds = [];

    private ?bool $priorRequirePure = null;

    #[Test]
    public function require_pure_epcis_document_defaults_to_false(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $settings = TenantSettings::forTenant(tenant());
            $settings->setRequirePureEpcisDocument(false);

            $this->assertFalse($settings->requirePureEpcisDocument());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function inbound_payload_resolver_unwraps_soap_by_default(): void
    {
        $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant(tenant())->setRequirePureEpcisDocument(false);

            $xml = file_get_contents(base_path('tests/Fixtures/epcis/soap_wrapped_minimal_object_shipping.xml'));
            $this->assertNotFalse($xml);

            $resolved = app(InboundPayloadResolver::class)->resolve($xml, 'application/xml', 'soap.xml');

            $this->assertStringContainsString('EPCISDocument', $resolved['content']);
            $this->assertStringNotContainsString('soapenv:Envelope', $resolved['content']);
            $this->assertSame('soap.xml', $resolved['originalName']);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function inbound_payload_resolver_rejects_soap_when_require_pure_is_on(): void
    {
        $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant(tenant())->setRequirePureEpcisDocument(true);

            $xml = file_get_contents(base_path('tests/Fixtures/epcis/soap_wrapped_minimal_object_shipping.xml'));
            $this->assertNotFalse($xml);

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage(EpcisSoapDocumentNormalizer::STRICT_REJECT_MESSAGE);

            app(InboundPayloadResolver::class)->resolve($xml, 'application/xml', 'soap.xml');
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function receive_epcis_upload_stores_unwrapped_payload_and_passes_xsd(): void
    {
        $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant(tenant())->setRequirePureEpcisDocument(false);

            [$tmp] = $this->uniqueFixture('tests/Fixtures/epcis/soap_wrapped_minimal_object_shipping.xml');

            $document = app(ReceiveEpcisUpload::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'soap_wrapped_minimal_object_shipping.xml',
                'dispatch' => false,
            ]);
            $this->documentIds[] = (int) $document->getKey();

            $this->assertSame('received', $document->status);

            $path = $document->materializePayloadPath();
            $stored = file_get_contents($path);
            $this->assertNotFalse($stored);
            $this->assertStringContainsString('EPCISDocument', $stored);
            $this->assertStringNotContainsString('soapenv:Envelope', $stored);

            $this->assertSame([], app(EpcisXsdValidator::class)->validateFile($path));

            @unlink($tmp);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function xsd_validator_unwraps_stored_soap_payload_when_allowed(): void
    {
        $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant(tenant())->setRequirePureEpcisDocument(false);

            $path = base_path('tests/Fixtures/epcis/soap_wrapped_minimal_object_shipping.xml');
            $findings = app(EpcisXsdValidator::class)->validateFile($path);

            $this->assertSame([], $findings);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function xsd_validator_surfaces_strict_reject_when_require_pure_is_on(): void
    {
        $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant(tenant())->setRequirePureEpcisDocument(true);

            $path = base_path('tests/Fixtures/epcis/soap_wrapped_minimal_object_shipping.xml');
            $findings = app(EpcisXsdValidator::class)->validateFile($path);

            $this->assertCount(1, $findings);
            $this->assertSame('INGESTION_PARSE_ERROR', $findings[0]->exceptionType);
            $this->assertSame(EpcisSoapDocumentNormalizer::STRICT_REJECT_MESSAGE, $findings[0]->description);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function receive_and_process_soap_wrapped_upload_succeeds_when_unwrap_allowed(): void
    {
        $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant(tenant())->setRequirePureEpcisDocument(false);

            [$tmp] = $this->uniqueFixture('tests/Fixtures/epcis/soap_wrapped_minimal_object_shipping.xml');

            $document = app(ReceiveEpcisUpload::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'soap_wrapped_minimal_object_shipping.xml',
                'dispatch' => false,
            ]);
            $this->documentIds[] = (int) $document->getKey();

            $processed = app(EpcisIngestionService::class)->process($document);

            $this->assertSame('validated', $processed->status);
            $this->assertNull($processed->error_message);

            @unlink($tmp);
        } finally {
            $this->cleanup();
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function uniqueFixture(string $relativePath): array
    {
        $source = base_path($relativePath);
        $tmp = tempnam(sys_get_temp_dir(), 'epcis_soap_');
        $this->assertNotFalse($tmp);
        $dest = $tmp.'.xml';
        rename($tmp, $dest);
        copy($source, $dest);

        return [$dest, $source];
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
        $this->priorRequirePure = TenantSettings::forTenant($tenant)->requirePureEpcisDocument();

        return $tenant;
    }

    private function cleanup(): void
    {
        if (tenancy()->initialized) {
            if ($this->priorRequirePure !== null) {
                TenantSettings::forTenant(tenant())->setRequirePureEpcisDocument($this->priorRequirePure);
                $this->priorRequirePure = null;
            }

            foreach ($this->documentIds as $id) {
                EpcisDocument::query()->whereKey($id)->delete();
            }
            $this->documentIds = [];

            if (Schema::hasTable('epcis_documents')) {
                // no-op: deletions above
            }
        }

        tenancy()->end();
    }
}
