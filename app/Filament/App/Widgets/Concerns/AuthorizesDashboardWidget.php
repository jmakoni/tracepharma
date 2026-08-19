<?php

namespace App\Filament\App\Widgets\Concerns;

use App\Models\User;
use App\Support\Dashboard\DashboardWidgetCatalog;
use App\Support\TenantFeatures;

trait AuthorizesDashboardWidget
{
    abstract public static function catalogKey(): string;

    public static function canView(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        return DashboardWidgetCatalog::isAvailable(
            static::catalogKey(),
            TenantFeatures::forTenant(tenant()),
            $user,
        );
    }
}
