<?php

namespace App\Support\Dashboard;

use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;

final class ResolveDashboardWidgets
{
    public function __construct(
        private readonly TenantSettings $settings,
        private readonly TenantFeatures $features,
    ) {}

    public static function make(?Tenant $tenant = null): self
    {
        $resolved = $tenant ?? (function_exists('tenant') && tenant() instanceof Tenant ? tenant() : null);

        return new self(
            TenantSettings::forTenant($resolved),
            TenantFeatures::forTenant($resolved),
        );
    }

    /**
     * Home widgets after tenant allow/default + user overlay, feature gates, and the home cap.
     *
     * @return list<string>
     */
    public function forUser(?User $user): array
    {
        $enabled = $this->settings->dashboardDefaults();

        if ($this->settings->dashboardAllowUserCustomize() && $user instanceof User && $user->hasDashboardWidgetPreferences()) {
            foreach ($user->dashboardWidgetPreferences() as $key => $on) {
                if (! is_string($key) || DashboardWidgetCatalog::definition($key) === null) {
                    continue;
                }

                $enabled[$key] = (bool) $on;
            }
        }

        return $this->applyHomeCap($this->enabledKeys($enabled, $user));
    }

    /**
     * Analytics suite widgets: allowed + available analytics keys. User home prefs are ignored.
     *
     * @return list<string>
     */
    public function forAnalyticsPage(?User $user): array
    {
        $allowed = $this->settings->dashboardAllowed();
        $keys = [];

        foreach (DashboardWidgetCatalog::analyticsKeys() as $key) {
            if (! ($allowed[$key] ?? true)) {
                continue;
            }

            if (! DashboardWidgetCatalog::isAvailable($key, $this->features, $user)) {
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
    private function enabledKeys(array $enabled, ?User $user): array
    {
        $allowed = $this->settings->dashboardAllowed();
        $keys = [];

        foreach (DashboardWidgetCatalog::keys() as $key) {
            if (! ($enabled[$key] ?? false)) {
                continue;
            }

            if (! ($allowed[$key] ?? $this->missingAllowedDefaultsTrue($key))) {
                continue;
            }

            if (! DashboardWidgetCatalog::isAvailable($key, $this->features, $user)) {
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
        if (count($keys) <= DashboardWidgetCatalog::HOME_CAP) {
            return array_values($keys);
        }

        $keepCtas = in_array('primary_ctas', $keys, true);
        $others = array_values(array_filter($keys, fn (string $key): bool => $key !== 'primary_ctas'));
        $slots = $keepCtas ? DashboardWidgetCatalog::HOME_CAP - 1 : DashboardWidgetCatalog::HOME_CAP;
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
            DashboardWidgetCatalog::keys(),
            fn (string $key): bool => array_key_exists($key, $lookup),
        ));
    }

    private function missingAllowedDefaultsTrue(string $key): bool
    {
        $definition = DashboardWidgetCatalog::definition($key);

        return $definition !== null && ($definition['kind'] === 'lean' || $definition['defaultOnHome']);
    }
}
