<?php

namespace Tests\Feature;

use App\Enums\TenantProfile;
use App\Filament\Admin\Resources\ActivityLogs\ActivityLogResource as AdminActivityLogResource;
use App\Filament\App\Resources\ActivityLogs\ActivityLogResource as AppActivityLogResource;
use App\Support\TenantFeatures;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ActivityLogResourceTest extends TestCase
{
    #[Test]
    public function app_activity_log_resource_is_read_only_and_nav_labeled(): void
    {
        $reflection = new \ReflectionClass(AppActivityLogResource::class);
        $label = $reflection->getProperty('navigationLabel');
        $label->setAccessible(true);
        $group = $reflection->getProperty('navigationGroup');
        $group->setAccessible(true);

        $this->assertSame('Activity Log', $label->getValue());
        $this->assertSame('Audit', $group->getValue());
        $this->assertFalse(AppActivityLogResource::canCreate());
    }

    #[Test]
    public function admin_activity_log_resource_is_read_only_and_nav_labeled(): void
    {
        $reflection = new \ReflectionClass(AdminActivityLogResource::class);
        $label = $reflection->getProperty('navigationLabel');
        $label->setAccessible(true);
        $group = $reflection->getProperty('navigationGroup');
        $group->setAccessible(true);

        $this->assertSame('Activity Log', $label->getValue());
        $this->assertSame('Audit', $group->getValue());
        $this->assertFalse(AdminActivityLogResource::canCreate());
    }

    #[Test]
    public function app_activity_log_follows_master_data_tenant_gating(): void
    {
        $this->assertTrue((new TenantFeatures(TenantProfile::Pharmacy))->supportsMasterData());
        $this->assertFalse((new TenantFeatures(TenantProfile::BuyingGroup))->supportsMasterData());
    }
}
