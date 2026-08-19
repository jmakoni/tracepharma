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
            ...self::navCapabilities(),
        ];
    }

    public static function navLabel(string $permission): string
    {
        return match ($permission) {
            self::NavReceive => 'Receive',
            self::NavShip => 'Ship',
            self::NavExceptions => 'Exceptions',
            self::NavVerify => 'Verify',
            self::NavMasterData => 'Master data',
            self::NavIntegrations => 'Integrations',
            self::NavCompliance => 'Compliance',
            self::NavUsers => 'Users',
            default => $permission,
        };
    }
}
