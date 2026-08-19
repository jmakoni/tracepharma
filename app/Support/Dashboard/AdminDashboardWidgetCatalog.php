<?php

namespace App\Support\Dashboard;

use App\Models\Admin;
use App\Support\Auth\Permissions;

final class AdminDashboardWidgetCatalog
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
                'key' => 'tenant_census',
                'kind' => 'lean',
                'label' => 'Tenant census',
                'description' => 'Tenant counts by profile and status.',
                'defaultOnHome' => true,
                'signal' => 'flow',
            ],
            [
                'key' => 'onboarding_queue',
                'kind' => 'lean',
                'label' => 'Onboarding queue',
                'description' => 'Submitted and approved onboardings, plus recent demo requests.',
                'defaultOnHome' => true,
                'signal' => 'friction',
            ],
            [
                'key' => 'registry_census',
                'kind' => 'lean',
                'label' => 'Registry census',
                'description' => 'FDA organizations, establishments, facilities, licenses, and products.',
                'defaultOnHome' => true,
                'signal' => 'flow',
            ],
            [
                'key' => 'registry_exceptions',
                'kind' => 'lean',
                'label' => 'Registry exceptions',
                'description' => 'Pending organization match reviews and unresolved unmatched facilities.',
                'defaultOnHome' => true,
                'signal' => 'friction',
            ],
            [
                'key' => 'import_health',
                'kind' => 'lean',
                'label' => 'Import health',
                'description' => 'Incomplete, failed, and partial FDA import runs.',
                'defaultOnHome' => true,
                'signal' => 'friction',
            ],
            [
                'key' => 'hub_health',
                'kind' => 'lean',
                'label' => 'Hub health',
                'description' => 'EPCIS hub token, provider, and host coverage by environment.',
                'defaultOnHome' => true,
                'signal' => 'friction',
            ],
            [
                'key' => 'primary_ctas',
                'kind' => 'lean',
                'label' => 'Primary actions',
                'description' => 'Tenants, onboardings, import runs, match reviews, hub, and analytics shortcuts.',
                'defaultOnHome' => true,
                'signal' => 'action',
            ],
            [
                'key' => 'tenant_growth',
                'kind' => 'analytics',
                'label' => 'Tenant growth',
                'description' => 'Tenants created by day and inbound environment.',
                'defaultOnHome' => false,
                'signal' => 'flow',
            ],
            [
                'key' => 'registry_growth',
                'kind' => 'analytics',
                'label' => 'Registry growth',
                'description' => 'FDA organizations and products created by day.',
                'defaultOnHome' => false,
                'signal' => 'flow',
            ],
            [
                'key' => 'onboarding_funnel',
                'kind' => 'analytics',
                'label' => 'Onboarding funnel',
                'description' => 'Onboarding status counts and time to provisioned.',
                'defaultOnHome' => false,
                'signal' => 'flow',
            ],
            [
                'key' => 'demo_volume',
                'kind' => 'analytics',
                'label' => 'Demo volume',
                'description' => 'Demo requests by day.',
                'defaultOnHome' => false,
                'signal' => 'flow',
            ],
            [
                'key' => 'import_trends',
                'kind' => 'analytics',
                'label' => 'Import trends',
                'description' => 'Import run outcomes by source over 7 and 30 days.',
                'defaultOnHome' => false,
                'signal' => 'friction',
            ],
            [
                'key' => 'unmatched_aging',
                'kind' => 'analytics',
                'label' => 'Unmatched aging',
                'description' => 'Unresolved unmatched facilities by last-seen age band.',
                'defaultOnHome' => false,
                'signal' => 'friction',
            ],
            [
                'key' => 'match_review_aging',
                'kind' => 'analytics',
                'label' => 'Match review aging',
                'description' => 'Pending match reviews by age and confidence.',
                'defaultOnHome' => false,
                'signal' => 'friction',
            ],
            [
                'key' => 'hub_coverage',
                'kind' => 'analytics',
                'label' => 'Hub coverage',
                'description' => 'Tenants with hub providers versus active routes by environment.',
                'defaultOnHome' => false,
                'signal' => 'friction',
            ],
            [
                'key' => 'activity_volume',
                'kind' => 'analytics',
                'label' => 'Activity volume',
                'description' => 'Central admin activity by day.',
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

    public static function isAvailable(string $key, ?Admin $admin): bool
    {
        $definition = self::definition($key);

        if ($definition === null || ! $admin instanceof Admin) {
            return false;
        }

        return match ($key) {
            'tenant_census', 'onboarding_queue', 'tenant_growth', 'onboarding_funnel', 'demo_volume' => $admin->can(Permissions::TenantsManage),
            'import_health', 'import_trends', 'unmatched_aging', 'match_review_aging', 'hub_health', 'hub_coverage' => $admin->can(Permissions::CatalogManage),
            'activity_volume' => $admin->can(Permissions::AdminsManage),
            'registry_census', 'registry_exceptions', 'registry_growth', 'primary_ctas' => true,
            default => false,
        };
    }
}
