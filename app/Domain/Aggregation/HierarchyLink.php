<?php

declare(strict_types=1);

namespace App\Domain\Aggregation;

/**
 * Pure parent/child link for hierarchy rebuild (no Eloquent).
 * Named HierarchyLink to avoid clashing with App\Models\Epcis\AggregationLink.
 */
final readonly class HierarchyLink
{
    public function __construct(
        public string $parentUri,
        public string $childUri,
    ) {}
}
