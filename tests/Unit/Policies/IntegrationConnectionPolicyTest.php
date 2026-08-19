<?php

namespace Tests\Unit\Policies;

use App\Enums\TenantRole;
use App\Models\InboundConnection;
use App\Models\LabelPrinter;
use App\Models\OutboundConnection;
use App\Models\User;
use App\Policies\InboundConnectionPolicy;
use App\Policies\LabelPrinterPolicy;
use App\Policies\OutboundConnectionPolicy;
use App\Support\Auth\JobRoleAccess;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class IntegrationConnectionPolicyTest extends TestCase
{
    #[Test]
    public function when_job_roles_are_off_only_owners_may_create_and_update_connections(): void
    {
        $this->assertFalse(JobRoleAccess::enabled());

        $inboundPolicy = new InboundConnectionPolicy;
        $outboundPolicy = new OutboundConnectionPolicy;
        $inbound = new InboundConnection;
        $outbound = new OutboundConnection;

        $owner = $this->userWithRoles(TenantRole::Owner);

        $this->assertTrue($inboundPolicy->create($owner));
        $this->assertTrue($inboundPolicy->update($owner, $inbound));
        $this->assertTrue($outboundPolicy->create($owner));
        $this->assertTrue($outboundPolicy->update($owner, $outbound));

        foreach ([
            TenantRole::ReceivingTechnician,
            TenantRole::CmoIntegrationManager,
            TenantRole::PharmacySystemAdministrator,
        ] as $role) {
            $user = $this->userWithRoles($role);
            $this->assertFalse($inboundPolicy->create($user), $role->value);
            $this->assertFalse($inboundPolicy->update($user, $inbound), $role->value);
            $this->assertFalse($outboundPolicy->create($user), $role->value);
            $this->assertFalse($outboundPolicy->update($user, $outbound), $role->value);
        }
    }

    #[Test]
    public function when_job_roles_are_off_only_owners_may_delete_connections(): void
    {
        $this->assertFalse(JobRoleAccess::enabled());

        $inboundPolicy = new InboundConnectionPolicy;
        $outboundPolicy = new OutboundConnectionPolicy;

        $this->assertTrue($inboundPolicy->deleteAny($this->userWithRoles(TenantRole::Owner)));
        $this->assertTrue($outboundPolicy->deleteAny($this->userWithRoles(TenantRole::Owner)));

        foreach ([
            TenantRole::CmoIntegrationManager,
            TenantRole::PharmacySystemAdministrator,
            TenantRole::ReceivingTechnician,
        ] as $role) {
            $this->assertFalse($inboundPolicy->deleteAny($this->userWithRoles($role)), $role->value);
            $this->assertFalse($outboundPolicy->deleteAny($this->userWithRoles($role)), $role->value);
        }
    }

    #[Test]
    public function label_printer_policy_uses_integration_maintainer_gates(): void
    {
        $this->assertFalse(JobRoleAccess::enabled());

        $policy = new LabelPrinterPolicy;
        $printer = new LabelPrinter;
        $owner = $this->userWithRoles(TenantRole::Owner);

        $this->assertTrue($policy->create($owner));
        $this->assertTrue($policy->update($owner, $printer));
        $this->assertTrue($policy->delete($owner, $printer));

        $technician = $this->userWithRoles(TenantRole::ReceivingTechnician);
        $this->assertFalse($policy->create($technician));
        $this->assertFalse($policy->update($technician, $printer));
        $this->assertFalse($policy->delete($technician, $printer));
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
