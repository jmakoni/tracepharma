<?php

namespace App\Support\Epcis;

use App\Models\Epcis\EpcisEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Persist readPoint/bizLocation rows for authored events in the same shape inbound ingest writes.
 */
final class PersistAuthoredEventLocations
{
    /**
     * @param  list<array{
     *     location_type: string,
     *     gln: ?string,
     *     gln_uri: ?string,
     *     site_id?: int|null,
     *     name?: ?string,
     *     street_address?: ?string,
     *     city?: ?string,
     *     state?: ?string,
     *     postal_code?: ?string,
     *     country_code?: ?string
     * }>  $locations
     */
    public function handle(EpcisEvent $event, array $locations): void
    {
        if ($locations === [] || ! Schema::hasTable('event_locations')) {
            return;
        }

        $rows = [];
        foreach ($locations as $location) {
            $gln = $location['gln'] ?? null;
            $uri = $location['gln_uri'] ?? null;
            if (! filled($gln) && ! filled($uri)) {
                continue;
            }

            $rows[] = [
                'event_id' => $event->getKey(),
                'location_type' => $location['location_type'],
                'gln' => $gln,
                'gln_uri' => filled($uri) ? (string) $uri : null,
                'name' => $location['name'] ?? null,
                'street_address' => $location['street_address'] ?? null,
                'city' => $location['city'] ?? null,
                'state' => $location['state'] ?? null,
                'postal_code' => $location['postal_code'] ?? null,
                'country_code' => $location['country_code'] ?? null,
                'latitude' => null,
                'longitude' => null,
                'site_id' => $location['site_id'] ?? null,
                'location_device_id' => null,
                'read_point_id' => null,
                'extra_json' => null,
            ];
        }

        if ($rows !== []) {
            DB::table('event_locations')->insert($rows);
        }
    }
}
