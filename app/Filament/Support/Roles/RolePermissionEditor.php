<?php

declare(strict_types=1);

namespace App\Filament\Support\Roles;

use App\Enums\AdminRole;
use App\Enums\TenantRole;
use App\Support\Auth\AdminRoleSeeder;
use App\Support\Auth\Permissions;
use App\Support\Auth\TenantRoleSeeder;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Sync / reset Spatie role permissions against the fixed Permissions catalog.
 */
final class RolePermissionEditor
{
    /**
     * @return array<string, string> permission name => label
     */
    public static function catalogOptions(string $guard): array
    {
        $names = $guard === 'admin'
            ? Permissions::adminPanelPermissions()
            : Permissions::tenantAppPermissions();

        $options = [];
        foreach ($names as $name) {
            $options[$name] = Permissions::label($name);
        }

        return $options;
    }

    public static function isCatalogRole(string $roleName, string $guard): bool
    {
        return self::defaultPermissionNames($roleName, $guard) !== null;
    }

    /**
     * @return list<string>|null null when the role name is outside the enum catalog
     */
    public static function defaultPermissionNames(string $roleName, string $guard): ?array
    {
        if ($guard === 'admin') {
            $role = AdminRole::tryFrom($roleName);

            return $role === null ? null : AdminRoleSeeder::permissionNamesFor($role);
        }

        $role = TenantRole::tryFrom($roleName);

        return $role === null ? null : TenantRoleSeeder::permissionNamesFor($role);
    }

    public static function roleLabel(string $roleName, string $guard): string
    {
        if ($guard === 'admin') {
            return AdminRole::tryFrom($roleName)?->label() ?? $roleName;
        }

        return TenantRole::tryFrom($roleName)?->label() ?? $roleName;
    }

    /**
     * @param  list<string|int>  $permissionNames
     * @return list<string>
     */
    public static function enforceProtectedPermissions(string $roleName, string $guard, array $permissionNames): array
    {
        $names = array_values(array_unique(array_map(
            static fn (string|int $name): string => (string) $name,
            $permissionNames,
        )));

        $catalog = array_keys(self::catalogOptions($guard));
        $names = array_values(array_intersect($names, $catalog));

        if ($guard === 'admin' && $roleName === AdminRole::PlatformAdmin->value) {
            foreach (Permissions::adminPanelPermissions() as $required) {
                if (! in_array($required, $names, true)) {
                    $names[] = $required;
                }
            }
        }

        if ($guard === 'web' && $roleName === TenantRole::Owner->value) {
            foreach ([Permissions::UsersManage, Permissions::NavUsers] as $required) {
                if (! in_array($required, $names, true)) {
                    $names[] = $required;
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  list<string|int>  $permissionNames
     */
    public static function sync(Role $role, array $permissionNames): void
    {
        $guard = (string) $role->guard_name;

        if (! self::isCatalogRole((string) $role->name, $guard)) {
            throw new InvalidArgumentException('Role is outside the seeded catalog.');
        }

        $names = self::enforceProtectedPermissions((string) $role->name, $guard, $permissionNames);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = array_map(
            static fn (string $name): Permission => Permission::findOrCreate($name, $guard),
            $names,
        );

        $role->syncPermissions($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public static function resetToDefaults(Role $role): void
    {
        $guard = (string) $role->guard_name;
        $defaults = self::defaultPermissionNames((string) $role->name, $guard);

        if ($defaults === null) {
            throw new InvalidArgumentException('Role is outside the seeded catalog.');
        }

        self::sync($role, $defaults);
    }
}
