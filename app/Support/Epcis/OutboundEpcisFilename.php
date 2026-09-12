<?php

namespace App\Support\Epcis;

use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Outbound EPCIS payload basename:
 * {tenant_name}_{env}_tracepharma_io_{datetime}_{tenant_id}-processed_data.xml
 *
 * Initial authoring stamps {datetime} from the shipping event time.
 * Retransmit / remint stamps {datetime} from prepare time so partners see a new file.
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

    /**
     * Allocate a free outbound basename + storage path for the given UTC stamp.
     * Advances the stamp by seconds (then a random suffix) if the object already exists.
     *
     * @return array{filename: string, path: string, stamp: CarbonInterface}
     */
    public static function allocateUnique(
        Tenant $tenant,
        CarbonInterface $stamp,
        string $extension = 'xml',
        ?string $disk = null,
    ): array {
        $extension = ltrim($extension, '.');
        $disk = $disk ?? (string) config('tracepharma.epcis.authored_payload_disk', 'local');
        $candidate = $stamp->copy()->utc();

        $filename = self::forShippingEvent($tenant, $candidate, $extension);
        $path = self::storagePath($tenant, $candidate, $extension);

        $guard = 0;
        while (Storage::disk($disk)->exists($path) && $guard < 5) {
            $candidate = $candidate->copy()->addSecond();
            $filename = self::forShippingEvent($tenant, $candidate, $extension);
            $path = self::storagePath($tenant, $candidate, $extension);
            $guard++;
        }

        if (Storage::disk($disk)->exists($path)) {
            $suffix = Str::lower(Str::random(6));
            $filename = preg_replace('/(\.[^.]+)$/', '-'.$suffix.'$1', $filename) ?: ($filename.'-'.$suffix);
            $path = 'epcis/outbound/'.$filename;
        }

        return [
            'filename' => $filename,
            'path' => $path,
            'stamp' => $candidate,
        ];
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
