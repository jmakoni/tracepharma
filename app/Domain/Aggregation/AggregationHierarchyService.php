<?php

declare(strict_types=1);

namespace App\Domain\Aggregation;

/**
 * Rebuild aggregation trees and detect drift after decommission.
 */
final class AggregationHierarchyService
{
    /**
     * @param  iterable<HierarchyLink|array{parent: string, child: string}|array{0: string, 1: string}>  $openLinks
     * @return list<HierarchyNode> root nodes (parents that are not children of another open link)
     */
    public function rebuildFromLinks(iterable $openLinks): array
    {
        /** @var array<string, list<string>> $childrenByParent */
        $childrenByParent = [];
        /** @var array<string, true> $childSet */
        $childSet = [];

        foreach ($openLinks as $link) {
            [$parent, $child] = $this->normalizeLink($link);
            if ($parent === '' || $child === '') {
                continue;
            }
            $childrenByParent[$parent][] = $child;
            $childSet[$child] = true;
        }

        $nodes = [];
        foreach (array_keys($childrenByParent) as $parentUri) {
            $nodes[$parentUri] = $this->buildNode($parentUri, $childrenByParent, []);
        }

        $roots = [];
        foreach ($nodes as $uri => $node) {
            if (! isset($childSet[$uri])) {
                $roots[] = $node;
            }
        }

        return array_values($roots);
    }

    /**
     * @param  list<string>  $decommissionedUris
     */
    public function detectDriftAfterDecommission(HierarchyNode $before, array $decommissionedUris): HierarchyDriftReport
    {
        $removed = array_values(array_unique(array_map('strval', $decommissionedUris)));
        $removedSet = array_fill_keys($removed, true);

        $orphaned = [];
        $brokenParents = [];
        $quantityGaps = [];

        $this->walkDrift($before, $removedSet, $orphaned, $brokenParents, $quantityGaps);

        return new HierarchyDriftReport(
            orphanedUris: array_values(array_unique($orphaned)),
            brokenParentRefs: array_values(array_unique($brokenParents)),
            removedUris: $removed,
            quantityGapsByParent: $quantityGaps,
        );
    }

    /**
     * @param  array<string, true>  $removedSet
     * @param  list<string>  $orphaned
     * @param  list<string>  $brokenParents
     * @param  array<string, int>  $quantityGaps
     */
    private function walkDrift(
        HierarchyNode $node,
        array $removedSet,
        array &$orphaned,
        array &$brokenParents,
        array &$quantityGaps,
    ): void {
        $selfRemoved = isset($removedSet[$node->epcUri]);

        if ($selfRemoved) {
            $liveDescendants = 0;
            foreach ($node->children as $child) {
                if (! isset($removedSet[$child->epcUri])) {
                    $orphaned[] = $child->epcUri;
                    $liveDescendants++;
                }
                $this->walkDrift($child, $removedSet, $orphaned, $brokenParents, $quantityGaps);
            }

            if ($liveDescendants > 0) {
                $brokenParents[] = $node->epcUri;
                $quantityGaps[$node->epcUri] = ($quantityGaps[$node->epcUri] ?? 0) + $liveDescendants;
            }

            return;
        }

        // Live node: any removed direct child → quantity gap (leaf or subtree).
        foreach ($node->children as $child) {
            if (isset($removedSet[$child->epcUri])) {
                $quantityGaps[$node->epcUri] = ($quantityGaps[$node->epcUri] ?? 0) + 1;
            }

            $this->walkDrift($child, $removedSet, $orphaned, $brokenParents, $quantityGaps);
        }
    }

    /**
     * @param  array<string, list<string>>  $childrenByParent
     * @param  array<string, true>  $stack
     */
    private function buildNode(string $uri, array $childrenByParent, array $stack): HierarchyNode
    {
        if (isset($stack[$uri])) {
            return new HierarchyNode($uri, []);
        }

        $stack[$uri] = true;
        $node = new HierarchyNode($uri, []);

        foreach ($childrenByParent[$uri] ?? [] as $childUri) {
            if (isset($childrenByParent[$childUri])) {
                $node->addChild($this->buildNode($childUri, $childrenByParent, $stack));
            } else {
                $node->addChild(new HierarchyNode($childUri, []));
            }
        }

        return $node;
    }

    /**
     * @param  HierarchyLink|array{parent?: string, child?: string, parent_uri?: string, child_uri?: string}|array{0: string, 1: string}  $link
     * @return array{0: string, 1: string}
     */
    private function normalizeLink(HierarchyLink|array $link): array
    {
        if ($link instanceof HierarchyLink) {
            return [$link->parentUri, $link->childUri];
        }

        if (array_is_list($link) && count($link) >= 2) {
            return [(string) $link[0], (string) $link[1]];
        }

        $parent = (string) ($link['parent'] ?? $link['parent_uri'] ?? '');
        $child = (string) ($link['child'] ?? $link['child_uri'] ?? '');

        return [$parent, $child];
    }
}
