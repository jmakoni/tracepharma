<?php

namespace App\Support\Dashboard;

use App\Models\Admin;
use App\Support\PlatformSettings;

final class ResolveAdminDashboardWidgets
{
    public static function make(): self
    {
        return new self();
    }

    /**
     * Home widgets after platform allow/default + admin overlay, permission gates, and the home cap.
     *
     * @return list<string>
     */
    public function forAdmin(?Admin $admin): array
    {
        $enabled = PlatformSettings::adminDashboardDefaults();

        if (PlatformSettings::adminDashboardAllowUserCustomize() && $admin instanceof Admin && $admin->hasDashboardWidgetPreferences()) {
            foreach ($admin->dashboardWidgetPreferences() as $key => $on) {
                if (! is_string($key) || AdminDashboardWidgetCatalog::definition($key) === null) {
                    continue;
                }

                $enabled[$key] = (bool) $on;
            }
        }

        return $this->applyHomeCap($this->enabledKeys($enabled, $admin));
    }

    /**
     * Analytics suite widgets: allowed + available analytics keys. Admin home prefs are ignored.
     *
     * @return list<string>
     */
    public function forAnalyticsPage(?Admin $admin): array
    {
        $allowed = PlatformSettings::adminDashboardAllowed();
        $keys = [];

        foreach (AdminDashboardWidgetCatalog::analyticsKeys() as $key) {
            if (! ($allowed[$key] ?? true)) {
                continue;
            }

            if (! AdminDashboardWidgetCatalog::isAvailable($key, $admin)) {
                continue;
            }

            $keys[] = $key;
        }

        return $keys;
    }

    /**
     * @param  array<string, bool>  $enabled
     * @return list<string>
     */
    private function enabledKeys(array $enabled, ?Admin $admin): array
    {
        $allowed = PlatformSettings::adminDashboardAllowed();
        $keys = [];

        foreach (AdminDashboardWidgetCatalog::keys() as $key) {
            if (! ($enabled[$key] ?? false)) {
                continue;
            }

            if (! ($allowed[$key] ?? $this->missingAllowedDefaultsTrue($key))) {
                continue;
            }

            if (! AdminDashboardWidgetCatalog::isAvailable($key, $admin)) {
                continue;
            }

            $keys[] = $key;
        }

        return $keys;
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function applyHomeCap(array $keys): array
    {
        if (count($keys) <= AdminDashboardWidgetCatalog::HOME_CAP) {
            return array_values($keys);
        }

        $keepCtas = in_array('primary_ctas', $keys, true);
        $others = array_values(array_filter($keys, fn (string $key): bool => $key !== 'primary_ctas'));
        $slots = $keepCtas ? AdminDashboardWidgetCatalog::HOME_CAP - 1 : AdminDashboardWidgetCatalog::HOME_CAP;
        $picked = array_slice($others, 0, $slots);

        if ($keepCtas) {
            $picked[] = 'primary_ctas';
        }

        return $this->inCatalogOrder($picked);
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function inCatalogOrder(array $keys): array
    {
        $lookup = array_flip($keys);

        return array_values(array_filter(
            AdminDashboardWidgetCatalog::keys(),
            fn (string $key): bool => array_key_exists($key, $lookup),
        ));
    }

    private function missingAllowedDefaultsTrue(string $key): bool
    {
        $definition = AdminDashboardWidgetCatalog::definition($key);

        return $definition !== null && ($definition['kind'] === 'lean' || $definition['defaultOnHome']);
    }
}
