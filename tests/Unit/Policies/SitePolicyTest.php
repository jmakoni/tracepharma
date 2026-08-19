<?php

namespace Tests\Unit\Policies;

use App\Enums\TenantRole;
use App\Models\Site;
use App\Models\User;
use App\Policies\SitePolicy;
use App\Support\Auth\JobRoleAccess;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SitePolicyTest extends TestCase
{
    #[Test]
    public function every_tenant_user_may_browse_sites(): void
    {
        $policy = new SitePolicy;
        $site = new Site;

        foreach ([TenantRole::ReceivingTechnician, TenantRole::VrsAnalyst, TenantRole::Owner] as $role) {
            $user = $this->userWithRoles($role);

            $this->assertTrue($policy->viewAny($user));
            $this->assertTrue($policy->view($user, $site));
        }
    }

    #[Test]
    public function when_job_roles_are_off_only_owners_may_create_and_update(): void
    {
        $this->assertFalse(JobRoleAccess::enabled());

        $policy = new SitePolicy;
        $site = new Site;

        $this->assertTrue($policy->create($this->userWithRoles(TenantRole::Owner)));
        $this->assertTrue($policy->update($this->userWithRoles(TenantRole::Owner), $site));

        foreach ([
            TenantRole::MasterDataAdministrator,
            TenantRole::ReceivingTechnician,
        ] as $role) {
            $user = $this->userWithRoles($role);
            $this->assertFalse($policy->create($user), $role->value);
            $this->assertFalse($policy->update($user, $site), $role->value);
        }
    }

    #[Test]
    public function when_job_roles_are_off_only_owners_may_delete(): void
    {
        $this->assertFalse(JobRoleAccess::enabled());

        $policy = new SitePolicy;

        $this->assertTrue($policy->deleteAny($this->userWithRoles(TenantRole::Owner)));

        foreach ([
            TenantRole::MasterDataAdministrator,
            TenantRole::PharmacySystemAdministrator,
            TenantRole::ReceivingTechnician,
        ] as $role) {
            $this->assertFalse($policy->deleteAny($this->userWithRoles($role)), $role->value);
        }
    }

    #[Test]
    public function a_user_without_roles_may_only_read(): void
    {
        $policy = new SitePolicy;
        $site = new Site;
        $user = $this->userWithRoles();

        $this->assertTrue($policy->viewAny($user));
        $this->assertFalse($policy->create($user));
        $this->assertFalse($policy->update($user, $site));
        $this->assertFalse($policy->deleteAny($user));
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
