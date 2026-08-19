<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Filament\Admin\Resources\CustomerOnboardings\CustomerOnboardingResource;
use App\Filament\Admin\Resources\DemoRequests\DemoRequestResource;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Models\Admin;
use App\Models\DemoRequest;
use App\Models\Tenant;
use App\Support\Auth\AdminRoleSeeder;
use App\Support\Auth\Permissions;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class OnboardingPermissionsTest extends TestCase
{
    /** @var list<int> */
    private array $adminIds = [];

    protected function tearDown(): void
    {
        if ($this->adminIds !== []) {
            Admin::query()->whereIn('id', $this->adminIds)->delete();
        }

        parent::tearDown();
    }

    #[Test]
    public function support_cannot_view_onboarding_or_demo_leads(): void
    {
        $support = $this->admin(AdminRole::Support);
        $this->actingAs($support, 'admin');

        $this->assertFalse($support->can(Permissions::TenantsManage));
        $this->assertFalse(CustomerOnboardingResource::canViewAny());
        $this->assertFalse(DemoRequestResource::canViewAny());
        $this->assertFalse(DemoRequestResource::canDelete(new DemoRequest));
        $this->assertFalse(TenantResource::canViewAny());
        $this->assertFalse(TenantResource::canCreate());
        $this->assertFalse(TenantResource::canDelete(new Tenant));
    }

    #[Test]
    public function platform_admin_can_view_onboarding_and_demo_leads(): void
    {
        $platformAdmin = $this->admin(AdminRole::PlatformAdmin);
        $this->actingAs($platformAdmin, 'admin');

        $this->assertTrue($platformAdmin->can(Permissions::TenantsManage));
        $this->assertTrue(CustomerOnboardingResource::canViewAny());
        $this->assertTrue(DemoRequestResource::canViewAny());
        $this->assertFalse(DemoRequestResource::canDelete(new DemoRequest));
        $this->assertTrue(TenantResource::canViewAny());
        $this->assertTrue(TenantResource::canCreate());
        $this->assertTrue(TenantResource::canDelete(new Tenant));
    }

    private function admin(AdminRole $role): Admin
    {
        app(AdminRoleSeeder::class)->seed();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = Admin::factory()->create();
        $admin->assignRole($role->value);
        $this->adminIds[] = (int) $admin->getKey();

        return $admin;
    }
}
