<?php

declare(strict_types=1);

namespace App\Domain\Aggregation;

/**
 * Drift detected after decommissioning nodes from an aggregation hierarchy.
 */
final readonly class HierarchyDriftReport
{
    /**
     * @param  list<string>  $orphanedUris
     * @param  list<string>  $brokenParentRefs
     * @param  list<string>  $removedUris
     * @param  array<string, int>  $quantityGapsByParent  parent URI → missing child count
     */
    public function __construct(
        public array $orphanedUris,
        public array $brokenParentRefs,
        public array $removedUris,
        public array $quantityGapsByParent,
    ) {}

    public function hasDrift(): bool
    {
        return $this->orphanedUris !== []
            || $this->brokenParentRefs !== []
            || $this->quantityGapsByParent !== [];
    }
}
