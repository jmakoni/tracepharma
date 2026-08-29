<?php

namespace App\Support\Epcis;

use App\Models\Tenant;
use Carbon\CarbonInterface;

/**
 * Outbound EPCIS payload basename:
 * {tenant_name}_{env}_tracepharma_io_{ship_event_datetime}_{tenant_id}-processed_data.xml
 */
final class OutboundEpcisFilename
{
    public static function forShippingEvent(Tenant $tenant, CarbonInterface $shipEventTime, string $extension = 'xml'): string
    {
        $tenantName = self::tenantName($tenant);
        $env = self::environmentSegment();
        $datetime = $shipEventTime->copy()->utc()->format('Ymd\THis\Z');
        $tenantId = (string) $tenant->getKey();
        $extension = ltrim($extension, '.');

        return "{$tenantName}_{$env}_tracepharma_io_{$datetime}_{$tenantId}-processed_data.{$extension}";
    }

    public static function storagePath(Tenant $tenant, CarbonInterface $shipEventTime, string $extension = 'xml'): string
    {
        return 'epcis/outbound/'.self::forShippingEvent($tenant, $shipEventTime, $extension);
    }

    private static function tenantName(Tenant $tenant): string
    {
        $domain = $tenant->domains()->value('domain');

        if (is_string($domain) && $domain !== '') {
            $label = strtolower((string) strtok($domain, '.'));

            if ($label !== '') {
                return preg_replace('/[^a-z0-9_-]+/', '', $label) ?: 'tenant';
            }
        }

        $name = strtolower((string) ($tenant->name ?? ''));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $name) ?: '';
        $slug = trim($slug, '-');

        if ($slug !== '') {
            return $slug;
        }

        return 'tenant';
    }

    private static function environmentSegment(): string
    {
        return app()->environment('production') ? 'prod' : 'stage';
    }
}
