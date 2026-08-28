<?php

namespace App\Support\Auth;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class TenantRoleSeeder
{
    public function seedForProfile(TenantProfile $profile, string $guard = 'web'): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [];
        foreach (Permissions::tenantAppPermissions() as $name) {
            $permissions[$name] = Permission::findOrCreate($name, $guard);
        }

        foreach (TenantRole::forProfile($profile) as $tenantRole) {
            $role = Role::findOrCreate($tenantRole->value, $guard);
            $names = self::permissionNamesFor($tenantRole);
            $role->syncPermissions(array_map(
                static fn (string $name): Permission => $permissions[$name],
                $names,
            ));
        }
    }

    /**
     * @return list<string>
     */
    public static function permissionNamesFor(TenantRole $role): array
    {
        return match ($role) {
            TenantRole::Owner => Permissions::tenantAppPermissions(),

            TenantRole::SupportEngineer => Permissions::tenantAppPermissions(),

            TenantRole::ReceivingTechnician => [
                Permissions::NavReceive,
            ],
            TenantRole::DispensingPharmacist => [
                Permissions::NavReceive,
                Permissions::NavVerify,
            ],
            TenantRole::PharmacyInventoryManager => [
                Permissions::NavReceive,
                Permissions::NavExceptions,
                Permissions::NavMasterData,
                Permissions::NavCompliance,
            ],
            TenantRole::PharmacySystemAdministrator => [
                Permissions::NavReceive,
                Permissions::NavExceptions,
                Permissions::NavVerify,
                Permissions::NavMasterData,
                Permissions::NavIntegrations,
                Permissions::NavCompliance,
                Permissions::NavUsers,
                Permissions::UsersManage,
                Permissions::SitesAccessAll,
            ],

            TenantRole::AtpVerificationManager => [
                Permissions::NavVerify,
                Permissions::NavMasterData,
                Permissions::NavCompliance,
            ],
            TenantRole::VrsAnalyst => [
                Permissions::NavVerify,
            ],
            TenantRole::CorporateComplianceAuditor => [
                Permissions::NavCompliance,
                Permissions::NavExceptions,
            ],
            TenantRole::BulkExceptionsManager => [
                Permissions::NavExceptions,
                Permissions::NavReceive,
            ],

            TenantRole::InboundExceptionCoordinator => [
                Permissions::NavExceptions,
                Permissions::NavReceive,
            ],
            TenantRole::WmsIntegrationSpecialist => [
                Permissions::NavIntegrations,
                Permissions::NavMasterData,
            ],
            TenantRole::OutboundPickAndPackLead => [
                Permissions::NavShip,
            ],
            TenantRole::QuarantineAndReturnsSpecialist => [
                Permissions::NavExceptions,
                Permissions::NavShip,
            ],

            TenantRole::PackagingLineOperator => [
                Permissions::NavShip,
            ],
            TenantRole::SerializationSystemsEngineer => [
                Permissions::NavIntegrations,
                Permissions::NavMasterData,
                Permissions::NavCompliance,
            ],
            TenantRole::MasterDataAdministrator => [
                Permissions::NavMasterData,
            ],
            TenantRole::CmoIntegrationManager => [
                Permissions::NavIntegrations,
                Permissions::NavMasterData,
            ],
        };
    }

    /**
     * Human labels for the capabilities a job role grants (Users form preview).
     *
     * @return list<string>
     */
    public static function capabilityLabelsFor(TenantRole $role): array
    {
        $labels = [];

        foreach (self::permissionNamesFor($role) as $name) {
            if (str_starts_with($name, 'nav.')) {
                $labels[] = Permissions::navLabel($name);
            } elseif ($name === Permissions::UsersManage) {
                $labels[] = 'Manage users';
            } elseif ($name === Permissions::SitesAccessAll) {
                $labels[] = 'All sites';
            }
        }

        return array_values(array_unique($labels));
    }
}
