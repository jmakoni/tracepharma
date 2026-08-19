<?php

namespace App\Support\Places;

interface PlacesClient
{
    /**
     * Search for places/businesses matching the given free-text query.
     *
     * @return list<array<string, mixed>> raw `data` items from the upstream API
     */
    public function search(string $query): array;
}
