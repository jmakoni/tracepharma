<?php

namespace App\Support\Auth;

use App\Enums\AdminRole;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class AdminRoleSeeder
{
    public function seed(string $guard = 'admin'): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [];
        foreach (Permissions::adminPanelPermissions() as $name) {
            $permissions[$name] = Permission::findOrCreate($name, $guard);
        }

        foreach (AdminRole::cases() as $adminRole) {
            $role = Role::findOrCreate($adminRole->value, $guard);
            $names = self::permissionNamesFor($adminRole);
            $role->syncPermissions(array_map(
                static fn (string $name): Permission => $permissions[$name],
                $names,
            ));
        }
    }

    /**
     * @return list<string>
     */
    public static function permissionNamesFor(AdminRole $role): array
    {
        return match ($role) {
            AdminRole::PlatformAdmin => Permissions::adminPanelPermissions(),
            AdminRole::Support => [],
        };
    }
}
