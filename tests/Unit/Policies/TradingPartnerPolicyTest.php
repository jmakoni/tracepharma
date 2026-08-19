<?php

namespace Tests\Unit\Policies;

use App\Enums\TenantRole;
use App\Models\TradingPartner;
use App\Models\User;
use App\Policies\TradingPartnerPolicy;
use App\Support\Auth\JobRoleAccess;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TradingPartnerPolicyTest extends TestCase
{
    #[Test]
    public function every_tenant_user_may_browse_partners(): void
    {
        $policy = new TradingPartnerPolicy;
        $partner = new TradingPartner;

        foreach ([TenantRole::ReceivingTechnician, TenantRole::VrsAnalyst, TenantRole::Owner] as $role) {
            $user = $this->userWithRoles($role);

            $this->assertTrue($policy->viewAny($user));
            $this->assertTrue($policy->view($user, $partner));
        }
    }

    #[Test]
    public function when_job_roles_are_off_only_owners_may_create_and_update(): void
    {
        $this->assertFalse(JobRoleAccess::enabled());

        $policy = new TradingPartnerPolicy;
        $partner = new TradingPartner;

        $this->assertTrue($policy->create($this->userWithRoles(TenantRole::Owner)));
        $this->assertTrue($policy->update($this->userWithRoles(TenantRole::Owner), $partner));

        foreach ([
            TenantRole::MasterDataAdministrator,
            TenantRole::ReceivingTechnician,
            TenantRole::VrsAnalyst,
        ] as $role) {
            $user = $this->userWithRoles($role);
            $this->assertFalse($policy->create($user), $role->value);
            $this->assertFalse($policy->update($user, $partner), $role->value);
        }
    }

    #[Test]
    public function when_job_roles_are_off_only_owners_may_delete_or_manage_portal_links(): void
    {
        $this->assertFalse(JobRoleAccess::enabled());

        $policy = new TradingPartnerPolicy;
        $partner = new TradingPartner;

        $this->assertTrue($policy->deleteAny($this->userWithRoles(TenantRole::Owner)));
        $this->assertTrue($policy->managePortalLink($this->userWithRoles(TenantRole::Owner), $partner));

        foreach ([
            TenantRole::MasterDataAdministrator,
            TenantRole::PharmacySystemAdministrator,
            TenantRole::AtpVerificationManager,
        ] as $role) {
            $this->assertFalse($policy->deleteAny($this->userWithRoles($role)), $role->value);
            $this->assertFalse($policy->managePortalLink($this->userWithRoles($role), $partner), $role->value);
        }
    }

    #[Test]
    public function a_user_without_roles_may_only_read(): void
    {
        $policy = new TradingPartnerPolicy;
        $partner = new TradingPartner;
        $user = $this->userWithRoles();

        $this->assertTrue($policy->viewAny($user));
        $this->assertFalse($policy->create($user));
        $this->assertFalse($policy->update($user, $partner));
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
