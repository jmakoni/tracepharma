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

        $manageAdmins = Permission::findOrCreate(Permissions::AdminsManage, $guard);
        $manageCatalog = Permission::findOrCreate(Permissions::CatalogManage, $guard);
        $manageTenants = Permission::findOrCreate(Permissions::TenantsManage, $guard);

        foreach (AdminRole::cases() as $adminRole) {
            $role = Role::findOrCreate($adminRole->value, $guard);

            if ($adminRole === AdminRole::PlatformAdmin) {
                $role->givePermissionTo($manageAdmins, $manageCatalog, $manageTenants);
            }
        }
    }
}
