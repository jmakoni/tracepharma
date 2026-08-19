<?php

namespace App\Filament\Admin\Widgets\Concerns;

use App\Models\Admin;
use App\Support\Dashboard\AdminDashboardWidgetCatalog;

trait AuthorizesAdminDashboardWidget
{
    abstract public static function catalogKey(): string;

    public static function canView(): bool
    {
        $admin = auth('admin')->user();

        if (! $admin instanceof Admin) {
            return false;
        }

        return AdminDashboardWidgetCatalog::isAvailable(static::catalogKey(), $admin);
    }
}
