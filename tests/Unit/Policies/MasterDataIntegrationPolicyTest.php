<?php

namespace Tests\Unit\Policies;

use App\Enums\TenantRole;
use App\Models\Device;
use App\Models\LocationDevice;
use App\Models\ReadPoint;
use App\Models\SsccNumberRange;
use App\Models\User;
use App\Policies\DevicePolicy;
use App\Policies\LocationDevicePolicy;
use App\Policies\ReadPointPolicy;
use App\Policies\SsccNumberRangePolicy;
use App\Support\Auth\JobRoleAccess;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MasterDataIntegrationPolicyTest extends TestCase
{
    #[Test]
    public function non_maintainer_cannot_create_device_read_point_location_device_or_sscc_range(): void
    {
        $this->assertFalse(JobRoleAccess::enabled());

        $devicePolicy = new DevicePolicy;
        $readPointPolicy = new ReadPointPolicy;
        $locationDevicePolicy = new LocationDevicePolicy;
        $ssccPolicy = new SsccNumberRangePolicy;

        $owner = $this->userWithRoles(TenantRole::Owner);
        $technician = $this->userWithRoles(TenantRole::ReceivingTechnician);

        $this->assertTrue($devicePolicy->create($owner));
        $this->assertTrue($readPointPolicy->create($owner));
        $this->assertTrue($locationDevicePolicy->create($owner));
        $this->assertTrue($ssccPolicy->create($owner));

        $this->assertFalse($devicePolicy->create($technician));
        $this->assertFalse($readPointPolicy->create($technician));
        $this->assertFalse($locationDevicePolicy->create($technician));
        $this->assertFalse($ssccPolicy->create($technician));
    }

    #[Test]
    public function non_maintainer_cannot_update_and_non_deleter_cannot_delete(): void
    {
        $this->assertFalse(JobRoleAccess::enabled());

        $device = new Device;
        $readPoint = new ReadPoint;
        $locationDevice = new LocationDevice;
        $sscc = new SsccNumberRange;

        $devicePolicy = new DevicePolicy;
        $readPointPolicy = new ReadPointPolicy;
        $locationDevicePolicy = new LocationDevicePolicy;
        $ssccPolicy = new SsccNumberRangePolicy;

        $technician = $this->userWithRoles(TenantRole::ReceivingTechnician);

        $this->assertFalse($devicePolicy->update($technician, $device));
        $this->assertFalse($readPointPolicy->update($technician, $readPoint));
        $this->assertFalse($locationDevicePolicy->update($technician, $locationDevice));
        $this->assertFalse($ssccPolicy->update($technician, $sscc));

        $this->assertFalse($devicePolicy->delete($technician, $device));
        $this->assertFalse($readPointPolicy->delete($technician, $readPoint));
        $this->assertFalse($locationDevicePolicy->delete($technician, $locationDevice));
        $this->assertFalse($ssccPolicy->delete($technician, $sscc));
    }

    private function userWithRoles(TenantRole ...$roles): User
    {
        $user = new User;

        $user->setRelation('roles', collect(array_map(
            static fn (TenantRole $role): Role => new Role(['name' => $role->value, 'guard_name' => 'web']),
            $roles,
        )));

        return $user;
    }
}
