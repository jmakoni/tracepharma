<?php

namespace Tests\Unit;

use App\Enums\FacilityType;
use App\Models\LocationDevice;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LocationDeviceAndFacilityTypeTest extends TestCase
{
    #[Test]
    public function location_device_factory_make_builds_attributes(): void
    {
        $device = LocationDevice::factory()->make([
            'site_id' => null,
        ]);

        $this->assertNotEmpty($device->name);
        $this->assertSame(13, strlen((string) $device->gln));
    }

    #[Test]
    public function facility_type_enum_has_wdd_and_3pl(): void
    {
        $this->assertSame('wdd', FacilityType::Wdd->value);
        $this->assertSame('3pl', FacilityType::ThreePl->value);
        $this->assertSame('WDD', FacilityType::Wdd->label());
        $this->assertSame('3PL', FacilityType::ThreePl->label());
    }
}
