<?php

declare(strict_types=1);

namespace Tests\Feature\Policies;

use App\Enums\AdminRole;
use App\Enums\AnnouncementStatus;
use App\Models\Admin;
use App\Models\Announcement;
use App\Models\Tenant;
use App\Policies\ActivityPolicy;
use App\Policies\AdminPolicy;
use App\Policies\AnnouncementPolicy;
use App\Policies\DemoRequestPolicy;
use App\Policies\FdaImportRunPolicy;
use App\Policies\FdaProductPolicy;
use App\Policies\FdaWddFacilityPolicy;
use App\Policies\RolePolicy;
use App\Policies\TenantPolicy;
use App\Support\Auth\AdminRoleSeeder;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminResourcePolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app(AdminRoleSeeder::class)->seed();
    }

    #[Test]
    public function platform_admin_can_manage_admins_and_cannot_delete_self(): void
    {
        $platform = $this->adminWithRole(AdminRole::PlatformAdmin);
        $other = Admin::factory()->create();

        $policy = new AdminPolicy;
        $this->assertTrue($policy->viewAny($platform));
        $this->assertTrue($policy->create($platform));
        $this->assertTrue($policy->delete($platform, $other));
        $this->assertFalse($policy->delete($platform, $platform));
    }

    #[Test]
    public function support_admin_is_denied_privileged_mutations(): void
    {
        $support = $this->adminWithRole(AdminRole::Support);

        $this->assertFalse((new AdminPolicy)->viewAny($support));
        $this->assertFalse((new TenantPolicy)->viewAny($support));
        $this->assertFalse((new AnnouncementPolicy)->create($support));
        $this->assertFalse((new DemoRequestPolicy)->viewAny($support));
        $this->assertTrue((new FdaWddFacilityPolicy)->viewAny($support));
        $this->assertFalse((new FdaWddFacilityPolicy)->create($support));
        $this->assertFalse((new FdaImportRunPolicy)->update($support, new \App\Models\Fda\FdaImportRun));
    }

    #[Test]
    public function announcement_delete_requires_draft_status(): void
    {
        $platform = $this->adminWithRole(AdminRole::PlatformAdmin);
        $policy = new AnnouncementPolicy;

        $draft = new Announcement(['status' => AnnouncementStatus::Draft]);
        $published = new Announcement(['status' => AnnouncementStatus::Published]);

        $this->assertTrue($policy->delete($platform, $draft));
        $this->assertFalse($policy->delete($platform, $published));
    }

    #[Test]
    public function platform_admin_tenant_and_catalog_abilities(): void
    {
        $platform = $this->adminWithRole(AdminRole::PlatformAdmin);

        $this->assertTrue((new TenantPolicy)->viewAny($platform));
        $this->assertTrue((new TenantPolicy)->delete($platform, new Tenant));
        $this->assertTrue((new FdaWddFacilityPolicy)->create($platform));
        $this->assertTrue((new FdaProductPolicy)->update($platform, new \App\Models\Fda\FdaProduct));
        $this->assertFalse((new FdaImportRunPolicy)->update($platform, new \App\Models\Fda\FdaImportRun));
    }

    #[Test]
    public function shared_role_and_activity_policies_accept_admin_actor(): void
    {
        $platform = $this->adminWithRole(AdminRole::PlatformAdmin);
        $support = $this->adminWithRole(AdminRole::Support);

        $this->assertTrue((new RolePolicy)->viewAny($platform));
        $this->assertFalse((new RolePolicy)->viewAny($support));
        $this->assertTrue((new ActivityPolicy)->viewAny($platform));
        $this->assertFalse((new ActivityPolicy)->viewAny($support));

        $this->assertInstanceOf(RolePolicy::class, Gate::getPolicyFor(Role::class));
        $this->assertInstanceOf(ActivityPolicy::class, Gate::getPolicyFor(Activity::class));
    }

    private function adminWithRole(AdminRole $role): Admin
    {
        $admin = Admin::factory()->create();
        $admin->assignRole($role->value);

        return $admin;
    }
}
