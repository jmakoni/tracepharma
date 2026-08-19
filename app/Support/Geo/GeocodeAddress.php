<?php

namespace App\Support\Geo;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resolve a postal address to coordinates via OpenStreetMap Nominatim.
 *
 * Hits are memoized for the current request and file-cached so Asset Tracking
 * does not burst Nominatim. Failures are swallowed: a missing pin is better
 * than a failed trace. 429s and timeouts are not stored as long cache misses.
 */
final class GeocodeAddress
{
    private const CACHE_TTL_SECONDS = 60 * 60 * 24 * 30;

    private const MISS_TTL_SECONDS = 60 * 60;

    private const MIN_LOOKUP_INTERVAL_SECONDS = 1.0;

    /** @var array<string, array{latitude: float, longitude: float}|false> */
    private static array $requestMemo = [];

    private static int $memoAppId = 0;

    private static ?float $lastLookupAt = null;

    /**
     * @return array{latitude: float, longitude: float}|null
     */
    public function handle(?string $address): ?array
    {
        $normalized = $this->normalize($address);
        if ($normalized === null) {
            return null;
        }

        $this->resetMemoIfNewApp();

        if (array_key_exists($normalized, self::$requestMemo)) {
            $memo = self::$requestMemo[$normalized];

            return $memo === false ? null : $memo;
        }

        $key = 'geocode:'.hash('sha256', $normalized);

        try {
            $cached = Cache::store('file')->get($key);
            if (is_array($cached) && isset($cached['latitude'], $cached['longitude'])) {
                return $this->remember($normalized, [
                    'latitude' => (float) $cached['latitude'],
                    'longitude' => (float) $cached['longitude'],
                ]);
            }

            if ($cached === false) {
                return $this->remember($normalized, null);
            }

            $lookup = $this->lookupThrottled($normalized);
            if ($lookup['cacheable']) {
                Cache::store('file')->put(
                    $key,
                    $lookup['coords'] ?? false,
                    $lookup['coords'] === null ? self::MISS_TTL_SECONDS : self::CACHE_TTL_SECONDS,
                );
            }

            return $this->remember($normalized, $lookup['coords']);
        } catch (ConnectionException) {
            Log::warning('Nominatim geocode failed.', ['status' => 'timeout']);

            return $this->remember($normalized, null);
        } catch (Throwable) {
            Log::warning('Nominatim geocode failed.', ['status' => 'error']);

            return $this->remember($normalized, null);
        }
    }

    /**
     * @return array{coords: ?array{latitude: float, longitude: float}, cacheable: bool}
     */
    private function lookupThrottled(string $address): array
    {
        if (app()->runningUnitTests()) {
            return $this->lookup($address);
        }

        $lock = Cache::store('file')->lock('geocode:nominatim', 15);

        try {
            $lock->block(10);
            $this->pace();

            return $this->lookup($address);
        } finally {
            optional($lock)->release();
        }
    }

    private function pace(): void
    {
        $now = microtime(true);
        if (self::$lastLookupAt !== null) {
            $wait = self::MIN_LOOKUP_INTERVAL_SECONDS - ($now - self::$lastLookupAt);
            if ($wait > 0) {
                usleep((int) ceil($wait * 1_000_000));
            }
        }

        self::$lastLookupAt = microtime(true);
    }

    /**
     * @return array{coords: ?array{latitude: float, longitude: float}, cacheable: bool}
     */
    private function lookup(string $address): array
    {
        $url = (string) config(
            'services.nominatim.url',
            'https://nominatim.openstreetmap.org/search',
        );
        $userAgent = (string) config(
            'services.nominatim.user_agent',
            'TracePharma/1.0 (asset-tracking; https://tracepharma.io)',
        );

        $response = Http::timeout(8)
            ->withHeaders([
                'Accept' => 'application/json',
                'User-Agent' => $userAgent,
            ])
            ->get($url, [
                'q' => $address,
                'format' => 'json',
                'limit' => 1,
            ]);

        if ($response->status() === 429) {
            Log::warning('Nominatim geocode failed.', ['status' => 429]);

            return ['coords' => null, 'cacheable' => false];
        }

        if ($response->failed()) {
            Log::warning('Nominatim geocode failed.', ['status' => $response->status()]);

            return ['coords' => null, 'cacheable' => false];
        }

        $first = $response->json('0');
        if (! is_array($first) || ! is_numeric($first['lat'] ?? null) || ! is_numeric($first['lon'] ?? null)) {
            return ['coords' => null, 'cacheable' => true];
        }

        return [
            'coords' => [
                'latitude' => (float) $first['lat'],
                'longitude' => (float) $first['lon'],
            ],
            'cacheable' => true,
        ];
    }

    /**
     * @param  array{latitude: float, longitude: float}|null  $coords
     * @return array{latitude: float, longitude: float}|null
     */
    private function remember(string $normalized, ?array $coords): ?array
    {
        self::$requestMemo[$normalized] = $coords ?? false;

        return $coords;
    }

    private function resetMemoIfNewApp(): void
    {
        $appId = spl_object_id(app());
        if (self::$memoAppId === $appId) {
            return;
        }

        self::$memoAppId = $appId;
        self::$requestMemo = [];
    }

    private function normalize(?string $address): ?string
    {
        if (! filled($address)) {
            return null;
        }

        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $address) ?? ''));
        if ($normalized === '') {
            return null;
        }

        $hasStreetOrPostal = preg_match('/\d/', $normalized) === 1;
        $hasLocality = str_contains($normalized, ',');

        if (! $hasStreetOrPostal && ! $hasLocality) {
            return null;
        }

        if (strlen($normalized) < 8) {
            return null;
        }

        return $normalized;
    }
}
