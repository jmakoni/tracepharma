<?php

namespace App\Support\Auth;

/**
 * Atomic app-panel capability permissions (job-role bundles).
 * TenantFeatures remains the hard ceiling; these only restrict further when job roles are on.
 */
final class Permissions
{
    public const UsersManage = 'users.manage';

    public const SitesAccessAll = 'sites.access_all';

    public const AdminsManage = 'admins.manage';

    /**
     * Destructive edits to the shared app catalog every tenant reads from.
     */
    public const CatalogManage = 'catalog.manage';

    /**
     * Provision tenants and read onboarding / demo lead PII.
     */
    public const TenantsManage = 'tenants.manage';

    public const NavReceive = 'nav.receive';

    public const NavShip = 'nav.ship';

    public const NavExceptions = 'nav.exceptions';

    public const NavVerify = 'nav.verify';

    public const NavMasterData = 'nav.master_data';

    public const NavIntegrations = 'nav.integrations';

    public const NavCompliance = 'nav.compliance';

    public const NavUsers = 'nav.users';

    /**
     * Second approver for mass decommission (N > threshold).
     */
    public const DecommissionMassApprove = 'decommission.mass_approve';

    /**
     * Skip outbound conformance ladder and force Live (audit-logged).
     */
    public const IntegrationsBreakGlass = 'integrations.break_glass';

    /**
     * Send on a live-ladder connection without expected_count (audit-logged override).
     */
    public const ShipQuantityGateOverride = 'shipping.quantity_gate_override';

    /**
     * @return list<string>
     */
    public static function navCapabilities(): array
    {
        return [
            self::NavReceive,
            self::NavShip,
            self::NavExceptions,
            self::NavVerify,
            self::NavMasterData,
            self::NavIntegrations,
            self::NavCompliance,
            self::NavUsers,
        ];
    }

    /**
     * @return list<string>
     */
    public static function tenantAppPermissions(): array
    {
        return [
            self::UsersManage,
            self::SitesAccessAll,
            self::DecommissionMassApprove,
            self::IntegrationsBreakGlass,
            self::ShipQuantityGateOverride,
            ...self::navCapabilities(),
        ];
    }

    /**
     * @return list<string>
     */
    public static function adminPanelPermissions(): array
    {
        return [
            self::AdminsManage,
            self::CatalogManage,
            self::TenantsManage,
        ];
    }

    public static function label(string $permission): string
    {
        return match ($permission) {
            self::UsersManage => 'Manage users',
            self::SitesAccessAll => 'All sites',
            self::AdminsManage => 'Manage admins',
            self::CatalogManage => 'Manage catalog',
            self::TenantsManage => 'Manage tenants',
            self::NavReceive => 'Receive',
            self::NavShip => 'Ship',
            self::NavExceptions => 'Exceptions',
            self::NavVerify => 'Verify',
            self::NavMasterData => 'Master data',
            self::NavIntegrations => 'Integrations',
            self::NavCompliance => 'Compliance',
            self::NavUsers => 'Users',
            self::DecommissionMassApprove => 'Mass decommission approve',
            self::IntegrationsBreakGlass => 'Integrations break-glass',
            self::ShipQuantityGateOverride => 'Ship quantity gate override',
            default => $permission,
        };
    }

    public static function navLabel(string $permission): string
    {
        return self::label($permission);
    }
}
