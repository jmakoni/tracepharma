<?php

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Enums\TenantProfile;
use App\Models\Epcis\EpcisDocument;
use App\Models\Tenant;
use App\Support\Epcis\EpcisXmlReader;
use App\Support\Epcis\Validation\EpcisValidationProfile;
use App\Support\Epcis\Validation\EpcisValidationProfileResolver;
use App\Support\Epcis\Validation\EpcisXsdValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class Epcis13IngestTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?int $documentId = null;

    #[Test]
    public function reader_and_xsd_accept_1_2_shaped_schema_version_1_3(): void
    {
        $path = base_path('tests/Fixtures/epcis/minimal_object_shipping_1.3.xml');

        $header = (new EpcisXmlReader)->parseHeader($path);
        $this->assertSame('1.3', $header['schema_version']);

        $this->assertSame([], app(EpcisXsdValidator::class)->validateFile($path));
    }

    #[Test]
    public function schema_version_1_3_does_not_force_guideline_r13(): void
    {
        config([
            'tracepharma.epcis.validation.default_profile' => 'gs1us_r12',
            'tracepharma.epcis.validation.force_r13' => false,
        ]);

        $path = base_path('tests/Fixtures/epcis/minimal_object_shipping_1.3.xml');
        $ctx = app(EpcisValidationProfileResolver::class)->resolve(
            new EpcisDocument(['direction' => 'inbound']),
            'inbound',
            $path,
        );

        $this->assertFalse($ctx->r13Hard);
        $this->assertSame(EpcisValidationProfile::Gs1UsR12, $ctx->profile);
    }

    #[Test]
    public function ingest_persists_schema_version_1_3(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping_1.3.xml');
            $tmp = tempnam(sys_get_temp_dir(), 'epcis13_');
            $this->assertNotFalse($tmp);
            $xml = file_get_contents($fixture);
            $this->assertNotFalse($xml);
            $xml = str_replace('11111111-2222-3333-4444-555555555555', (string) str()->uuid(), $xml);
            file_put_contents($tmp, $xml);

            $document = app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'minimal_object_shipping_1.3.xml',
            ]);
            $this->documentId = (int) $document->getKey();

            $this->assertSame('1.3', $document->schema_version);
            $this->assertSame('validated', $document->status);

            @unlink($tmp);
        } finally {
            if (tenancy()->initialized) {
                if ($this->documentId !== null) {
                    EpcisDocument::query()->whereKey($this->documentId)->delete();
                }
                tenancy()->end();
            }
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
}
