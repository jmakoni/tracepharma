<?php

namespace App\Policies\Concerns;

use App\Enums\TenantRole;
use App\Models\User;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;

/**
 * Master-data mutate gates for trading partners, sites, and products.
 *
 * Job roles off (default): Owner only — personas must not silently gain write access.
 * Job roles on: requires nav.master_data; deletes stay with owner / master-data admin personas.
 */
trait MaintainsMasterData
{
    /**
     * @var list<TenantRole>
     */
    private const DELETERS = [
        TenantRole::Owner,
        TenantRole::MasterDataAdministrator,
        TenantRole::PharmacySystemAdministrator,
    ];

    private function isMaintainer(User $user): bool
    {
        if (! JobRoleAccess::enabled()) {
            return $user->hasRole(TenantRole::Owner->value);
        }

        return $user->can(Permissions::NavMasterData);
    }

    private function isDeleter(User $user): bool
    {
        if (! JobRoleAccess::enabled()) {
            return $user->hasRole(TenantRole::Owner->value);
        }

        return $user->can(Permissions::NavMasterData)
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
