<?php

namespace App\Filament\Admin\Support;

use App\Models\Admin;
use App\Support\Auth\Permissions;
use Illuminate\Database\Eloquent\Model;

trait ViewOnlyFdaRegistryResource
{
    public static function canViewAny(): bool
    {
        return auth('admin')->user() instanceof Admin;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        $admin = auth('admin')->user();

        return $admin instanceof Admin
            && $admin->can(Permissions::CatalogManage);
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }
}
