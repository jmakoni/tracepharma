<?php

namespace Tests\Feature;

use App\Enums\AuthorizationStatus;
use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Support\MasterData\ProductComplianceStatus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductComplianceStatusTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $tenantPartnerIds = [];

    /** @var list<int> */
    private array $tenantProductIds = [];

    #[Test]
    public function verified_when_all_pivots_authorized_and_manufacturer_linked(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $manufacturer = TradingPartner::query()->create([
                'name' => 'Mfg Verified '.uniqid(),
                'gln' => fake()->unique()->numerify('#############'),
                'partner_type' => PartnerType::Manufacturer,
                'country_code' => 'US',
                'is_active' => true,
            ]);

            $wholesaler = TradingPartner::query()->create([
                'name' => 'Wh Verified '.uniqid(),
                'gln' => fake()->unique()->numerify('#############'),
                'partner_type' => PartnerType::Wholesaler,
                'country_code' => 'US',
                'is_active' => true,
            ]);
            $this->tenantPartnerIds = [$manufacturer->id, $wholesaler->id];

            $product = Product::query()->create([
                'name' => 'Verified Product '.uniqid(),
                'trading_partner_id' => $manufacturer->id,
                'gtin' => fake()->unique()->numerify('##############'),
                'is_active' => true,
            ]);
            $this->tenantProductIds = [$product->id];

            $wholesaler->products()->attach($product->id, [
                'authorization_status' => AuthorizationStatus::Authorized->value,
                'authorized_at' => now(),
            ]);

            $product->load('tradingPartners');

            $this->assertSame(
                ProductComplianceStatus::Verified,
                ProductComplianceStatus::label($product),
            );
        } finally {
            $this->cleanupIntegrationFixtures();
        }
    }

    #[Test]
    public function verified_for_manufacturer_direct_receive_without_trading_partner_fk(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $manufacturer = TradingPartner::query()->create([
                'name' => 'Mfg Direct '.uniqid(),
                'gln' => fake()->unique()->numerify('#############'),
                'partner_type' => PartnerType::Manufacturer,
                'country_code' => 'US',
                'is_active' => true,
            ]);
            $this->tenantPartnerIds = [$manufacturer->id];

            $product = Product::query()->create([
                'name' => 'Direct Mfg Product '.uniqid(),
                'gtin' => fake()->unique()->numerify('##############'),
                'is_active' => true,
            ]);
            $this->tenantProductIds = [$product->id];

            $manufacturer->products()->attach($product->id, [
                'authorization_status' => AuthorizationStatus::Authorized->value,
                'authorized_at' => now(),
            ]);

            $product->load('tradingPartners');

            $this->assertSame(
                ProductComplianceStatus::Verified,
                ProductComplianceStatus::label($product),
            );
        } finally {
            $this->cleanupIntegrationFixtures();
        }
    }

    #[Test]
    public function pending_manufacturer_when_any_pivot_pending(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $wholesaler = TradingPartner::query()->create([
                'name' => 'Wh Pending '.uniqid(),
                'gln' => fake()->unique()->numerify('#############'),
                'partner_type' => PartnerType::Wholesaler,
                'country_code' => 'US',
                'is_active' => true,
            ]);
            $this->tenantPartnerIds = [$wholesaler->id];

            $product = Product::query()->create([
                'name' => 'Pending Product '.uniqid(),
                'gtin' => fake()->unique()->numerify('##############'),
                'is_active' => true,
            ]);
            $this->tenantProductIds = [$product->id];

            $wholesaler->products()->attach($product->id, [
                'authorization_status' => AuthorizationStatus::PendingManufacturer->value,
                'authorized_at' => now(),
            ]);

            $product->load('tradingPartners');

            $this->assertSame(
                ProductComplianceStatus::PendingManufacturer,
                ProductComplianceStatus::label($product),
            );
        } finally {
            $this->cleanupIntegrationFixtures();
        }
    }

    #[Test]
    public function incomplete_when_authorized_pivots_but_manufacturer_missing(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $wholesaler = TradingPartner::query()->create([
                'name' => 'Wh Incomplete '.uniqid(),
                'gln' => fake()->unique()->numerify('#############'),
                'partner_type' => PartnerType::Wholesaler,
                'country_code' => 'US',
                'is_active' => true,
            ]);
            $this->tenantPartnerIds = [$wholesaler->id];

            $product = Product::query()->create([
                'name' => 'Incomplete Product '.uniqid(),
                'gtin' => fake()->unique()->numerify('##############'),
                'is_active' => true,
            ]);
            $this->tenantProductIds = [$product->id];

            $wholesaler->products()->attach($product->id, [
                'authorization_status' => AuthorizationStatus::Authorized->value,
                'authorized_at' => now(),
            ]);

            $product->load('tradingPartners');

            $this->assertSame(
                ProductComplianceStatus::Incomplete,
                ProductComplianceStatus::label($product),
            );
        } finally {
            $this->cleanupIntegrationFixtures();
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

    private function cleanupIntegrationFixtures(): void
    {
        if (tenancy()->initialized) {
            if ($this->tenantPartnerIds !== []) {
                foreach ($this->tenantPartnerIds as $partnerId) {
                    TradingPartner::query()->find($partnerId)?->products()->detach();
                }
            }

            if ($this->tenantProductIds !== []) {
                Product::query()->whereIn('id', $this->tenantProductIds)->delete();
            }

            if ($this->tenantPartnerIds !== []) {
                TradingPartner::query()->whereIn('id', $this->tenantPartnerIds)->delete();
            }

            tenancy()->end();
        }

        $this->tenantPartnerIds = [];
        $this->tenantProductIds = [];
    }
}
