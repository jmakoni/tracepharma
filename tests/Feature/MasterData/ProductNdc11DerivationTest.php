<?php

namespace Tests\Feature\MasterData;

use App\Enums\TenantProfile;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\Gs1\Gtin;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductNdc11DerivationTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $productIds = [];

    #[Test]
    public function it_derives_ndc11_from_the_package_ndc_on_create(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $labeler = $this->uniqueLabeler();

            $product = $this->createProduct([
                'package_ndc' => $labeler.'-4023-16',
                'ndc' => $labeler.'-4023',
            ]);

            $this->assertSame('0'.$labeler.'402316', $product->fresh()->ndc11);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function it_falls_back_to_the_product_ndc_when_no_package_ndc_exists(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $labeler = $this->uniqueLabeler();

            $product = $this->createProduct(['ndc' => '9'.$labeler.'-678-90']);

            $this->assertSame('9'.$labeler.'067890', $product->fresh()->ndc11);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function it_leaves_ndc11_null_when_the_source_ndc_is_ambiguous(): void
    {
        $this->initializeDemo2Tenant();

        try {
            // A bare 10-digit NDC cannot be expanded without its segment boundaries.
            $product = $this->createProduct(['package_ndc' => $this->uniqueLabeler().'402316']);

            $this->assertNull($product->fresh()->ndc11);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function it_does_not_overwrite_an_explicitly_supplied_ndc11(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $labeler = $this->uniqueLabeler();
            $explicit = '9'.$labeler.'00001';

            $product = $this->createProduct([
                'ndc11' => $explicit,
                'package_ndc' => $labeler.'-4023-16',
            ]);

            $this->assertSame($explicit, $product->fresh()->ndc11);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function it_re_derives_ndc11_when_the_package_ndc_changes(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $first = $this->uniqueLabeler();
            $second = $this->uniqueLabeler();

            $product = $this->createProduct(['package_ndc' => $first.'-4023-16']);
            $this->assertSame('0'.$first.'402316', $product->fresh()->ndc11);

            $product->package_ndc = $second.'-4023-16';
            $product->save();

            $this->assertSame('0'.$second.'402316', $product->fresh()->ndc11);
        } finally {
            $this->cleanup();
        }
    }

    /**
     * A 4-digit labeler code unlikely to collide with tenant fixture data.
     */
    private function uniqueLabeler(): string
    {
        return (string) random_int(7000, 9999);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createProduct(array $attributes): Product
    {
        $suffix = (string) random_int(100000, 999999);
        $body13 = '0061414'.$suffix;

        $product = Product::query()->create([
            'gtin' => $body13.Gtin::checkDigit($body13),
            'name' => 'NDC Derivation Product '.uniqid(),
            'is_active' => true,
            ...$attributes,
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
