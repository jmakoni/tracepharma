<?php

namespace App\Support\Places;

use RuntimeException;

/**
 * Places client backed by a static JSON fixture file, shaped like the real
 * upstream API response (`{"status": "OK", "data": [...]}`). Used in tests
 * (and local development) via container binding instead of hitting the
 * network. The query is intentionally ignored: the fixture file itself
 * determines which "search" it represents.
 */
final class FixturePlacesClient implements PlacesClient
{
    public function __construct(private readonly string $path) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function search(string $query): array
    {
        if (! is_file($this->path)) {
            throw new RuntimeException("Places fixture not found at [{$this->path}].");
        }

        $payload = json_decode((string) file_get_contents($this->path), true);

        if (! is_array($payload)) {
            throw new RuntimeException("Places fixture at [{$this->path}] does not contain valid JSON.");
        }

        $data = $payload['data'] ?? [];

        return is_array($data) ? array_values($data) : [];
    }
}
