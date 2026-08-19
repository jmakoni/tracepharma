<?php

namespace App\Support\Tracing;

use App\Models\Epcis\EventLocation;
use App\Models\Site;
use App\Models\TradingPartner;
use App\Support\Catalog\PartnerLocationDisplay;
use App\Support\Geo\GeocodeAddress;

/**
 * Resolve a GLN / EPCIS event location into a display-ready label, address, and coordinates.
 *
 * Lookup order for a GLN: an EventLocation row's own name/address/lat/lng (already
 * enriched at ingest time) → tenant Site by GLN → tenant TradingPartner by GLN.
 * EventLocation name/address without coordinates still uses Site/Partner lat/lng,
 * then geocodes the postal address (Nominatim) when those are also blank.
 */
final class LocationDisplayResolver
{
    /** @var array<string, ?Site> */
    private array $sitesByGln = [];

    /** @var array<string, ?TradingPartner> */
    private array $partnersByGln = [];

    public function __construct(private readonly GeocodeAddress $geocodeAddress) {}

    /**
     * @return array{name: ?string, gln: ?string, address: ?string, label: string, latitude: ?float, longitude: ?float}
     */
    public function resolve(?string $gln, ?EventLocation $location = null): array
    {
        $normalizedGln = $this->normalizeGln($gln ?? $location?->gln);

        if ($location !== null && $this->eventLocationHasSignal($location)) {
            $name = filled($location->name) ? (string) $location->name : null;
            [$latitude, $longitude] = $this->eventLocationCoordinates($location);

            if ($latitude === null || $longitude === null) {
                $fallback = $this->masterDataCoordinates($normalizedGln);
                $latitude ??= $fallback['latitude'];
                $longitude ??= $fallback['longitude'];
            }

            return $this->withGeocodedCoordinates([
                'name' => $name,
                'gln' => $normalizedGln,
                'address' => $this->eventLocationAddress($location)
                    ?? $this->masterDataAddress($normalizedGln),
                'label' => $name ?? $normalizedGln ?? '—',
                'latitude' => $latitude,
                'longitude' => $longitude,
            ]);
        }

        if ($normalizedGln === null) {
            return $this->empty(null);
        }

        $site = $this->siteForGln($normalizedGln);
        if ($site !== null) {
            return $this->withGeocodedCoordinates([
                'name' => filled($site->name) ? (string) $site->name : null,
                'gln' => $normalizedGln,
                'address' => PartnerLocationDisplay::addressLine($site),
                'label' => filled($site->name) ? (string) $site->name : $normalizedGln,
                'latitude' => $site->latitude !== null ? (float) $site->latitude : null,
                'longitude' => $site->longitude !== null ? (float) $site->longitude : null,
            ]);
        }

        $partner = $this->partnerForGln($normalizedGln);
        if ($partner !== null) {
            return $this->withGeocodedCoordinates([
                'name' => filled($partner->name) ? (string) $partner->name : null,
                'gln' => $normalizedGln,
                'address' => PartnerLocationDisplay::addressLine($partner),
                'label' => filled($partner->name) ? (string) $partner->name : $normalizedGln,
                'latitude' => $partner->latitude !== null ? (float) $partner->latitude : null,
                'longitude' => $partner->longitude !== null ? (float) $partner->longitude : null,
            ]);
        }

        return $this->empty($normalizedGln);
    }

    /**
     * Batch-load Sites/TradingPartners for a set of GLNs to avoid N+1 lookups
     * when resolving many events/timeline steps at once.
     *
     * @param  array<int, ?string>  $glns
     */
    public function preloadForGlns(array $glns): void
    {
        $normalized = array_values(array_unique(array_filter(
            array_map(fn (?string $gln): ?string => $this->normalizeGln($gln), $glns),
        )));

        if ($normalized === []) {
            return;
        }

        $missingForSites = array_values(array_diff($normalized, array_keys($this->sitesByGln)));
        if ($missingForSites !== []) {
            $sites = Site::query()->whereIn('gln', $missingForSites)->get()->keyBy('gln');
            foreach ($missingForSites as $gln) {
                $this->sitesByGln[$gln] = $sites->get($gln);
            }
        }

        $missingForPartners = array_values(array_diff($normalized, array_keys($this->partnersByGln)));
        if ($missingForPartners !== []) {
            $partners = TradingPartner::query()->whereIn('gln', $missingForPartners)->get()->keyBy('gln');
            foreach ($missingForPartners as $gln) {
                $this->partnersByGln[$gln] = $partners->get($gln);
            }
        }
    }

    private function siteForGln(string $gln): ?Site
    {
        if (array_key_exists($gln, $this->sitesByGln)) {
            return $this->sitesByGln[$gln];
        }

        return $this->sitesByGln[$gln] = Site::query()->where('gln', $gln)->first();
    }

    private function partnerForGln(string $gln): ?TradingPartner
    {
        if (array_key_exists($gln, $this->partnersByGln)) {
            return $this->partnersByGln[$gln];
        }

        return $this->partnersByGln[$gln] = TradingPartner::query()->where('gln', $gln)->first();
    }

    /**
     * @return array{0: ?float, 1: ?float}
     */
    private function eventLocationCoordinates(EventLocation $location): array
    {
        $attributes = $location->getAttributes();
        $lat = $attributes['latitude'] ?? $location->latitude;
        $lng = $attributes['longitude'] ?? $location->longitude;

        if ($lat === null || $lat === '' || $lng === null || $lng === '') {
            return [null, null];
        }

        return [(float) $lat, (float) $lng];
    }

    /**
     * Display-only geocode. Never write coordinates onto Site, Partner, or EventLocation
     * from a read path (Asset Tracking, timeline labels).
     *
     * @param  array{name: ?string, gln: ?string, address: ?string, label: string, latitude: ?float, longitude: ?float}  $resolved
     * @return array{name: ?string, gln: ?string, address: ?string, label: string, latitude: ?float, longitude: ?float}
     */
    private function withGeocodedCoordinates(array $resolved): array
    {
        if ($resolved['latitude'] !== null && $resolved['longitude'] !== null) {
            return $resolved;
        }

        $address = $resolved['address'] ?? $this->masterDataAddress($resolved['gln']);
        $coords = $this->geocodeAddress->handle($address);
        if ($coords === null) {
            return $resolved;
        }

        $resolved['latitude'] = $coords['latitude'];
        $resolved['longitude'] = $coords['longitude'];

        return $resolved;
    }

    private function masterDataAddress(?string $gln): ?string
    {
        if ($gln === null) {
            return null;
        }

        $site = $this->siteForGln($gln);
        if ($site !== null) {
            $address = PartnerLocationDisplay::addressLine($site);
            if (filled($address)) {
                return $address;
            }
        }

        $partner = $this->partnerForGln($gln);
        if ($partner !== null) {
            return PartnerLocationDisplay::addressLine($partner);
        }

        return null;
    }

    /**
     * @return array{latitude: ?float, longitude: ?float}
     */
    private function masterDataCoordinates(?string $gln): array
    {
        if ($gln === null) {
            return ['latitude' => null, 'longitude' => null];
        }

        $site = $this->siteForGln($gln);
        if ($site !== null && $site->latitude !== null && $site->longitude !== null) {
            return [
                'latitude' => (float) $site->latitude,
                'longitude' => (float) $site->longitude,
            ];
        }

        $partner = $this->partnerForGln($gln);
        if ($partner !== null && $partner->latitude !== null && $partner->longitude !== null) {
            return [
                'latitude' => (float) $partner->latitude,
                'longitude' => (float) $partner->longitude,
            ];
        }

        return ['latitude' => null, 'longitude' => null];
    }

    private function eventLocationHasSignal(EventLocation $location): bool
    {
        return filled($location->name)
            || filled($location->street_address)
            || $location->latitude !== null
            || $location->longitude !== null;
    }

    private function eventLocationAddress(EventLocation $location): ?string
    {
        $lines = [];

        if (filled($location->street_address)) {
            $lines[] = trim((string) $location->street_address);
        }

        $cityStateZip = implode(', ', array_filter([
            filled($location->city) ? trim((string) $location->city) : null,
            filled($location->state) ? strtoupper(trim((string) $location->state)) : null,
        ]));

        if (filled($location->postal_code)) {
            $cityStateZip = trim($cityStateZip.' '.trim((string) $location->postal_code));
        }

        if ($cityStateZip !== '') {
            $lines[] = $cityStateZip;
        }

        if (filled($location->country_code)) {
            $lines[] = PartnerLocationDisplay::countryName($location->country_code) ?? (string) $location->country_code;
        }

        return $lines === [] ? null : implode(' ', $lines);
    }

    /**
     * @return array{name: ?string, gln: ?string, address: ?string, label: string, latitude: ?float, longitude: ?float}
     */
    private function empty(?string $gln): array
    {
        return [
            'name' => null,
            'gln' => $gln,
            'address' => null,
            'label' => $gln ?? '—',
            'latitude' => null,
            'longitude' => null,
        ];
    }

    private function normalizeGln(mixed $gln): ?string
    {
        if ($gln === null || $gln === '') {
            return null;
        }

        $normalized = preg_replace('/\D+/', '', (string) $gln) ?? '';

        return $normalized !== '' ? $normalized : null;
    }
}
