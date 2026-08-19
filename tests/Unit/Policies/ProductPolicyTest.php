<?php

namespace Tests\Unit\Policies;

use App\Enums\TenantRole;
use App\Models\Product;
use App\Models\User;
use App\Policies\ProductPolicy;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * With job roles off (default), only Owners mutate master data.
 */
class ProductPolicyTest extends TestCase
{
    #[Test]
    public function every_tenant_user_may_browse_products(): void
    {
        $policy = new ProductPolicy;
        $product = new Product;

        foreach ([TenantRole::ReceivingTechnician, TenantRole::VrsAnalyst, TenantRole::Owner] as $role) {
            $user = $this->userWithRoles($role);

            $this->assertTrue($policy->viewAny($user));
            $this->assertTrue($policy->view($user, $product));
        }
    }

    #[Test]
    public function when_job_roles_are_off_only_owners_may_maintain_the_assortment(): void
    {
        $this->assertFalse(\App\Support\Auth\JobRoleAccess::enabled());

        $policy = new ProductPolicy;
        $product = new Product;

        $owner = $this->userWithRoles(TenantRole::Owner);
        $this->assertTrue($policy->create($owner));
        $this->assertTrue($policy->update($owner, $product));
        $this->assertTrue($policy->attach($owner, $product));
        $this->assertTrue($policy->detach($owner, $product));
        $this->assertTrue($policy->delete($owner, $product));

        foreach ([
            TenantRole::MasterDataAdministrator,
            TenantRole::PharmacyInventoryManager,
            TenantRole::ReceivingTechnician,
            TenantRole::VrsAnalyst,
        ] as $role) {
            $user = $this->userWithRoles($role);

            $this->assertFalse($policy->create($user), $role->value.' must not maintain while job roles are off.');
            $this->assertFalse($policy->update($user, $product), $role->value);
            $this->assertFalse($policy->delete($user, $product), $role->value);
        }
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
