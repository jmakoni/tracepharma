<?php

namespace Tests\Feature\Shipping;

use App\Enums\TenantProfile;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\Epcis\BuildFullHistoryShippingEpcisXml;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The EPCClass vocabulary this platform authors is read verbatim by trading partners,
 * so `FDA_NDC_11` must never label anything other than a real NDC-11.
 */
class ShippingEpcisTradeItemIdentificationTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $productIds = [];

    #[Test]
    public function it_emits_fda_ndc_11_only_for_a_real_ndc11(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $gtin14 = '00301164023161';
            $this->createProduct($gtin14, ndc11: '00116402316');

            $xml = $this->epcClassVocabularyXml($gtin14);

            $this->assertStringContainsString('FDA_NDC_11', $xml);
            $this->assertStringContainsString('>00116402316</attribute>', $xml);
            $this->assertStringNotContainsString('>'.$gtin14.'</attribute>', $xml);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function it_omits_the_identification_rather_than_labelling_a_gtin_as_an_ndc(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $gtin14 = '00301164023185';
            $this->createProduct($gtin14, ndc11: null);

            $xml = $this->epcClassVocabularyXml($gtin14);

            $this->assertStringNotContainsString('FDA_NDC_11', $xml);
            $this->assertStringNotContainsString('additionalTradeItemIdentification', $xml);
            $this->assertStringNotContainsString($gtin14, $xml);
            $this->assertStringContainsString('regulatedProductName', $xml);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function it_derives_the_ndc11_from_the_package_ndc_when_the_column_is_empty(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $gtin14 = '00301164023208';
            $product = $this->createProduct($gtin14, ndc11: null, packageNdc: '0116-4023-20');

            // The observer fills ndc11 on save, so the vocabulary has a real NDC to emit.
            $this->assertSame('00116402320', $product->fresh()->ndc11);

            $xml = $this->epcClassVocabularyXml($gtin14);

            $this->assertStringContainsString('FDA_NDC_11', $xml);
            $this->assertStringContainsString('>00116402320</attribute>', $xml);
        } finally {
            $this->cleanup();
        }
    }

    private function epcClassVocabularyXml(string $gtin14): string
    {
        $method = new ReflectionMethod(BuildFullHistoryShippingEpcisXml::class, 'epcClassVocabularyXml');

        $parsed = [
            'company_prefix' => '030116',
            'indicator_digit' => substr($gtin14, 0, 1),
            'item_reference' => substr($gtin14, 7, 6),
            'gtin14' => $gtin14,
        ];

        return (string) $method->invoke(
            app(BuildFullHistoryShippingEpcisXml::class),
            [$parsed['company_prefix'].'.'.$parsed['indicator_digit'].$parsed['item_reference'] => $parsed],
        );
    }

    private function createProduct(string $gtin14, ?string $ndc11, ?string $packageNdc = null): Product
    {
        $product = Product::query()->create([
            'gtin' => $gtin14,
            'name' => 'Promethazine Hydrochloride '.uniqid(),
            'strength' => '6.25mg/5mL',
            'dosage_form' => 'SYRUP',
            'ndc11' => $ndc11,
            'package_ndc' => $packageNdc,
            'is_active' => true,
        ]);

        $this->productIds[] = (int) $product->getKey();

        return $product;
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

        if ($this->productIds !== []) {
            Product::query()->whereIn('id', $this->productIds)->delete();
            $this->productIds = [];
        }

        tenancy()->end();
    }
}
