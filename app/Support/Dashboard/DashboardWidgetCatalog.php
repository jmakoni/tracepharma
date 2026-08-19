<?php

namespace App\Support\Dashboard;

use App\Models\User;
use App\Support\Auth\Permissions;
use App\Support\TenantFeatures;

final class DashboardWidgetCatalog
{
    public const HOME_CAP = 8;

    /**
     * @return list<array{
     *     key: string,
     *     kind: 'lean'|'analytics',
     *     label: string,
     *     description: string,
     *     defaultOnHome: bool,
     *     signal: 'flow'|'friction'|'recovery'|'action'
     * }>
     */
    public static function all(): array
    {
        return [
            [
                'key' => 'floor_queue',
                'kind' => 'lean',
                'label' => 'Floor queue',
                'description' => 'Open receive and ship sessions for the current site, with a path to Operations Hub.',
                'defaultOnHome' => true,
                'signal' => 'flow',
            ],
            [
                'key' => 'today_activity',
                'kind' => 'lean',
                'label' => 'Today’s activity',
                'description' => 'Last 24 hours of received, shipped, and exception activity.',
                'defaultOnHome' => true,
                'signal' => 'flow',
            ],
            [
                'key' => 'compliance_pulse',
                'kind' => 'lean',
                'label' => 'Compliance pulse',
                'description' => 'Open exceptions, quarantine holds, and tracing requests ranked by due date.',
                'defaultOnHome' => true,
                'signal' => 'friction',
            ],
            [
                'key' => 'integration_health',
                'kind' => 'lean',
                'label' => 'Integration health',
                'description' => 'Inbound and outbound integration failures in the last 24 hours.',
                'defaultOnHome' => true,
                'signal' => 'friction',
            ],
            [
                'key' => 'primary_ctas',
                'kind' => 'lean',
                'label' => 'Primary actions',
                'description' => 'Receive, ship, verify, and Operations Hub shortcuts.',
                'defaultOnHome' => true,
                'signal' => 'action',
            ],
            [
                'key' => 'volume_trends',
                'kind' => 'analytics',
                'label' => 'Volume trends',
                'description' => 'Receive and ship completions by day.',
                'defaultOnHome' => false,
                'signal' => 'flow',
            ],
            [
                'key' => 'exception_aging',
                'kind' => 'analytics',
                'label' => 'Exception aging',
                'description' => 'Open exceptions by age band and severity.',
                'defaultOnHome' => false,
                'signal' => 'friction',
            ],
            [
                'key' => 'vrs_rates',
                'kind' => 'analytics',
                'label' => 'VRS rates',
                'description' => 'Verification allowed, blocked, deferred, and unavailable rates.',
                'defaultOnHome' => false,
                'signal' => 'friction',
            ],
            [
                'key' => 'partner_throughput',
                'kind' => 'analytics',
                'label' => 'Partner throughput',
                'description' => 'Top trading partners by receive and ship volume.',
                'defaultOnHome' => false,
                'signal' => 'flow',
            ],
            [
                'key' => 'integration_trends',
                'kind' => 'analytics',
                'label' => 'Integration trends',
                'description' => 'Daily integration success versus failure.',
                'defaultOnHome' => false,
                'signal' => 'friction',
            ],
            [
                'key' => 'atp_expiry',
                'kind' => 'analytics',
                'label' => 'ATP license expiry',
                'description' => 'ATP licenses expiring in 30, 60, and 90 days.',
                'defaultOnHome' => false,
                'signal' => 'friction',
            ],
            [
                'key' => 'tracing_sla_score',
                'kind' => 'analytics',
                'label' => 'Tracing SLA',
                'description' => 'On-time tracing percentage and at-risk requests.',
                'defaultOnHome' => false,
                'signal' => 'recovery',
            ],
            [
                'key' => 'site_comparison',
                'kind' => 'analytics',
                'label' => 'Site comparison',
                'description' => 'Per-site receive and ship volume. Requires access to all sites.',
                'defaultOnHome' => false,
                'signal' => 'flow',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_column(self::all(), 'key');
    }

    /**
     * @return list<string>
     */
    public static function leanKeys(): array
    {
        return array_values(array_map(
            fn (array $definition): string => $definition['key'],
            array_filter(self::all(), fn (array $definition): bool => $definition['kind'] === 'lean'),
        ));
    }

    /**
     * @return list<string>
     */
    public static function analyticsKeys(): array
    {
        return array_values(array_map(
            fn (array $definition): string => $definition['key'],
            array_filter(self::all(), fn (array $definition): bool => $definition['kind'] === 'analytics'),
        ));
    }

    /**
     * @return array{
     *     key: string,
     *     kind: 'lean'|'analytics',
     *     label: string,
     *     description: string,
     *     defaultOnHome: bool,
     *     signal: 'flow'|'friction'|'recovery'|'action'
     * }|null
     */
    public static function definition(string $key): ?array
    {
        foreach (self::all() as $definition) {
            if ($definition['key'] === $key) {
                return $definition;
            }
        }

        return null;
    }

    public static function isAvailable(string $key, ?TenantFeatures $features, ?User $user): bool
    {
        $definition = self::definition($key);

        if ($definition === null) {
            return false;
        }

        $features ??= TenantFeatures::forTenant(tenant());

        $featureOn = match ($key) {
            'floor_queue', 'volume_trends', 'partner_throughput' => $features->supportsReceiving()
                || $features->supportsOutboundIntegrations(),
            'today_activity' => $features->hasAnyOperations(),
            'compliance_pulse' => $features->supportsComplianceCases()
                || $features->supportsTracingRequests(),
            'integration_health', 'integration_trends' => $features->supportsInboundIntegrations()
                || $features->supportsOutboundIntegrations(),
            'primary_ctas' => $features->hasAnyOperations() || $features->supportsVrs(),
            'exception_aging' => $features->supportsComplianceCases(),
            'vrs_rates' => $features->supportsVrs(),
            'atp_expiry' => $features->supportsMasterData(),
            'tracing_sla_score' => $features->supportsTracingRequests(),
            'site_comparison' => $features->supportsReceiving() || $features->supportsOutboundIntegrations(),
            default => false,
        };

        if (! $featureOn) {
            return false;
        }

        if ($key === 'site_comparison') {
            return $user instanceof User && $user->can(Permissions::SitesAccessAll);
        }

        return true;
    }
}
