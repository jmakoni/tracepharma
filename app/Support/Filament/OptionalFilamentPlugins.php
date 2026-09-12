<?php

declare(strict_types=1);

namespace App\Support\Filament;

use Filament\Panel;

/**
 * Register third-party Filament plugins only when their package is present.
 *
 * Keeps panel providers bootable when an optional composer package is removed
 * without editing every `use` import (factories are not invoked when missing).
 */
final class OptionalFilamentPlugins
{
    /**
     * @param  callable(): object  $factory
     */
    public static function register(Panel $panel, string $pluginClass, callable $factory): Panel
    {
        if (! class_exists($pluginClass)) {
            return $panel;
        }

        return $panel->plugin($factory());
    }
}
