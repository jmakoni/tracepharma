<?php

namespace App\Policies\Concerns;

use App\Enums\TenantRole;
use App\Models\User;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;

/**
 * Integration connection mutate gates (inbound/outbound endpoints, tokens).
 *
 * Job roles off (default): Owner only — integration viewers must not silently gain write access.
 * Job roles on: requires users.manage; deletes stay with owner / integration admin personas.
 */
trait MaintainsIntegrations
{
    /**
     * @var list<TenantRole>
     */
    private const DELETERS = [
        TenantRole::Owner,
        TenantRole::CmoIntegrationManager,
        TenantRole::WmsIntegrationSpecialist,
        TenantRole::PharmacySystemAdministrator,
    ];

    private function isMaintainer(User $user): bool
    {
        if (! JobRoleAccess::enabled()) {
            return $user->hasRole(TenantRole::Owner->value);
        }

        return $user->can(Permissions::UsersManage);
    }

    private function isDeleter(User $user): bool
    {
        if (! JobRoleAccess::enabled()) {
            return $user->hasRole(TenantRole::Owner->value);
        }

        return $user->can(Permissions::UsersManage)
            && $this->hasAnyRole($user, self::DELETERS);
    }

    /**
     * @param  list<TenantRole>  $roles
     */
    private function hasAnyRole(User $user, array $roles): bool
    {
        return $user->hasAnyRole(array_map(
            static fn (TenantRole $role): string => $role->value,
            $roles,
        ));
    }
}
