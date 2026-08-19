<?php

namespace App\Support\Places;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Places search via RapidAPI Local Business Data
 * (https://local-business-data.p.rapidapi.com/search).
 *
 * Auth uses `x-rapidapi-key` + `x-rapidapi-host` headers. Configure via
 * PLACES_API_* in .env (see config/services.php).
 */
final class HttpPlacesClient implements PlacesClient
{
    /**
     * @return list<array<string, mixed>>
     */
    public function search(string $query): array
    {
        $config = config('services.places', []);

        if (! ($config['enabled'] ?? false)) {
            throw new RuntimeException(
                'Places API is not enabled. Set PLACES_API_KEY to enable it.'
            );
        }

        $baseUrl = $config['base_url'] ?? null;
        $apiKey = $config['api_key'] ?? null;
        $host = $config['host'] ?? 'local-business-data.p.rapidapi.com';

        if (blank($baseUrl) || blank($apiKey)) {
            throw new RuntimeException('Places API is missing required base_url or api_key configuration.');
        }

        $response = Http::timeout(30)
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'x-rapidapi-host' => $host,
                'x-rapidapi-key' => $apiKey,
            ])
            ->get($baseUrl, [
                'query' => $query,
                'limit' => $config['limit'] ?? 20,
                'zoom' => $config['zoom'] ?? 13,
                'language' => $config['language'] ?? 'en',
                'region' => $config['region'] ?? 'us',
                'extract_emails_and_contacts' => 'false',
            ]);

        if ($response->failed()) {
            throw new RuntimeException("Places API request failed with HTTP status {$response->status()}.");
        }

        $payload = $response->json();

        if (! is_array($payload) || ($payload['status'] ?? null) !== 'OK') {
            return [];
        }

        $data = $payload['data'] ?? [];

        return is_array($data) ? array_values($data) : [];
    }
}
