<?php

namespace App\Support;

use App\Enums\SsccAllocationMode;
use App\Models\SsccSerialPool;
use App\Models\Tenant;

/**
 * Tenant SSCC labeling settings.
 *
 * Company prefix comes from organization identity ({@see TenantSettings::companyPrefix()});
 * remaining SSCC options live under tenant settings.sscc.
 */
class TenantSsccSettings
{
    /**
     * @return array{
     *     company_prefix: ?string,
     *     extension_digit: int,
     *     next_serial_reference: int,
     *     default_allocation_mode: SsccAllocationMode,
     *     last_serial_reference_int: ?int,
     *     last_printed_serial_reference_int: ?int,
     *     enforce_forward_only: bool,
     *     low_water_mark: int
     * }
     */
    public static function resolve(?Tenant $tenant = null): array
    {
        $tenant ??= tenant();
        $settings = self::ssccBag($tenant);
        $companyPrefix = TenantSettings::forTenant($tenant)->companyPrefix();
        $extensionDigit = (int) ($settings['extension_digit'] ?? 0);

        $pool = null;
        if ($companyPrefix !== null && $tenant !== null && tenancy()->initialized) {
            $pool = SsccSerialPool::query()
                ->where('company_prefix', $companyPrefix)
                ->where('extension_digit', (string) $extensionDigit)
                ->first();
        }

        return [
            'company_prefix' => $companyPrefix,
            'extension_digit' => $extensionDigit,
            'next_serial_reference' => (int) ($settings['next_serial_reference'] ?? 1),
            'default_allocation_mode' => SsccAllocationMode::tryFrom((string) ($settings['default_allocation_mode'] ?? SsccAllocationMode::Sequential->value))
                ?? SsccAllocationMode::Sequential,
            'last_serial_reference_int' => $pool?->last_serial_reference_int,
            'last_printed_serial_reference_int' => $pool?->last_printed_serial_reference_int,
            'enforce_forward_only' => (bool) ($settings['enforce_forward_only'] ?? true),
            'low_water_mark' => (int) ($settings['low_water_mark'] ?? config('sscc.default_low_water_mark', 5000)),
        ];
    }

    /**
     * @param  array<string, mixed>  $ssccSettings
     */
    public static function persist(array $ssccSettings, ?Tenant $tenant = null): void
    {
        $tenant ??= tenant();

        if ($tenant === null) {
            return;
        }

        $settings = is_array($tenant->getAttribute('settings'))
            ? $tenant->getAttribute('settings')
            : [];

        $settings['sscc'] = array_merge(
            is_array($settings['sscc'] ?? null) ? $settings['sscc'] : [],
            $ssccSettings,
        );

        $tenant->setAttribute('settings', $settings);
        $tenant->save();
    }

    public static function syncNextSerialReference(int $nextSerialReference, ?Tenant $tenant = null): void
    {
        self::persist([
            'next_serial_reference' => max(1, $nextSerialReference),
        ], $tenant);
    }

    /**
     * @deprecated Prefer serial pool allocation via labeling services.
     */
    public static function reserveNextSerialReference(?Tenant $tenant = null): int
    {
        $settings = self::resolve($tenant);
        $current = max(1, $settings['next_serial_reference']);

        self::persist([
            'next_serial_reference' => $current + 1,
        ], $tenant);

        return $current;
    }

    /**
     * @return array<string, mixed>
     */
    private static function ssccBag(?Tenant $tenant): array
    {
        $settings = $tenant?->getAttribute('settings');

        if (! is_array($settings)) {
            return [];
        }

        return is_array($settings['sscc'] ?? null) ? $settings['sscc'] : [];
    }
}
