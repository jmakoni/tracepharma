<?php

namespace Tests\Feature;

use App\Enums\TenantProfile;
use App\Filament\App\Resources\Devices\DeviceResource;
use App\Models\Device;
use App\Models\Product;
use App\Support\TenantFeatures;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MasterDataTest extends TestCase
{
    #[Test]
    public function pharmacy_supports_master_data(): void
    {
        $this->assertTrue((new TenantFeatures(TenantProfile::Pharmacy))->supportsMasterData());
    }

    #[Test]
    public function buying_group_hides_master_data(): void
    {
        $this->assertFalse((new TenantFeatures(TenantProfile::BuyingGroup))->supportsMasterData());
    }

    #[Test]
    public function product_factory_builds_valid_attributes(): void
    {
        $product = Product::factory()->make();

        $this->assertSame(14, strlen((string) $product->gtin));
        $this->assertNotEmpty($product->name);
        $this->assertTrue($product->is_active);
    }

    #[Test]
    public function device_factory_builds_valid_attributes(): void
    {
        $device = Device::factory()->make([
            'site_id' => null,
        ]);

        $this->assertNotEmpty($device->name);
        $this->assertNotNull($device->device_type);
        $this->assertTrue($device->is_active);
    }

    #[Test]
    public function device_resource_is_gated_by_master_data(): void
    {
        $this->assertTrue((new TenantFeatures(TenantProfile::Pharmacy))->supportsMasterData());
        $this->assertFalse((new TenantFeatures(TenantProfile::BuyingGroup))->supportsMasterData());
        $this->assertTrue(method_exists(DeviceResource::class, 'canAccess'));
    }
}
