<?php

declare(strict_types=1);

namespace App\Domain\Aggregation;

/**
 * In-memory aggregation tree node (parent SSCC / SGTIN → children).
 */
final class HierarchyNode
{
    /**
     * @param  list<HierarchyNode>  $children
     */
    public function __construct(
        public readonly string $epcUri,
        public array $children = [],
    ) {}

    public function addChild(HierarchyNode $child): void
    {
        $this->children[] = $child;
    }

    /**
     * @return list<string>
     */
    public function descendantUris(): array
    {
        $uris = [];

        foreach ($this->children as $child) {
            $uris[] = $child->epcUri;
            foreach ($child->descendantUris() as $uri) {
                $uris[] = $uri;
            }
        }

        return $uris;
    }
}
