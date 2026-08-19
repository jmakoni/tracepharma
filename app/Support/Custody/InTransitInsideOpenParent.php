<?php

namespace App\Support\Custody;

use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;

/**
 * "It left inside its container": an EPC nobody scanned onto a shipment is out of
 * our hands anyway once an open aggregation parent above it has been handed off.
 *
 * A shipping event names the outermost units — the SSCC on the pallet, not the
 * cases and items packed inside it — so a packed child's own latest trackable
 * event stays the aggregation or receiving event read at our dock. Custody checks
 * and shippable-inventory listings read that event
 * ({@see ResolveEpcLastKnownGln}) and would keep offering stock that is on a
 * truck, so both ask this class whether an ancestor took the child with it.
 * Whatever counts as a handoff for the container counts for its contents —
 * partner shipment or intracompany transfer alike ({@see OutboundShipmentInTransit}).
 *
 * Only open links are climbed: once a hierarchy is unpacked the child is a unit
 * in its own right and answers for its own location. Depth is bounded by the same
 * hierarchy limit the EPCIS validators use, so a malformed cycle costs a fixed
 * number of queries instead of hanging.
 */
final class InTransitInsideOpenParent
{
    public function __construct(private readonly ResolveEpcLastKnownGln $lastKnownGln) {}

    public function matches(Epc|int $epc): bool
    {
        return $this->inTransitAncestorByEpcId([$epc]) !== [];
    }

    /**
     * The handed-off ancestor of each EPC that has one, climbing open links.
     *
     * One pair of queries per level of hierarchy, whatever the number of EPCs
     * asked about, and only while unresolved packed items remain: stock with no
     * open parent — the usual case for a scanned SSCC — costs a single query.
     *
     * @param  iterable<Epc|int>  $epcs
     * @return array<int, int> EPC id => id of the ancestor that carried it away
     */
    public function inTransitAncestorByEpcId(iterable $epcs): array
    {
        /** @var array<int, list<int>> $frontier container being inspected => the asked-about EPCs beneath it */
        $frontier = [];

        foreach ($epcs as $epc) {
            $epcId = $epc instanceof Epc ? (int) $epc->getKey() : (int) $epc;

            if ($epcId > 0) {
                $frontier[$epcId] = [$epcId];
            }
        }

        if ($frontier === []) {
            return [];
        }

        $ancestors = [];
        $depthLimit = self::depthLimit();

        for ($depth = 0; $depth < $depthLimit && $frontier !== []; $depth++) {
            $parents = $this->openParentsOf($frontier);
            $frontier = [];

            if ($parents === []) {
                break;
            }

            $metas = $this->lastKnownGln->latestEventMetaForEpcIds(array_keys($parents));

            foreach ($parents as $parentId => $packedEpcIds) {
                if (OutboundShipmentInTransit::matches($metas[$parentId] ?? null)) {
                    foreach ($packedEpcIds as $epcId) {
                        $ancestors[$epcId] ??= $parentId;
                    }

                    continue;
                }

                // This container is still on hand, but one above it may not be.
                $unresolved = array_values(array_filter(
                    $packedEpcIds,
                    fn (int $epcId): bool => ! isset($ancestors[$epcId]),
                ));

                if ($unresolved !== []) {
                    $frontier[$parentId] = $unresolved;
                }
            }
        }

        return $ancestors;
    }

    /**
     * One level up: the open parent of each EPC in the frontier, carrying forward
     * which of the originally asked-about EPCs sit beneath it.
     *
     * @param  array<int, list<int>>  $frontier
     * @return array<int, list<int>>
     */
    private function openParentsOf(array $frontier): array
    {
        $links = AggregationLink::query()
            ->open()
            ->whereIn('child_epc_id', array_keys($frontier))
            ->get(['parent_epc_id', 'child_epc_id']);

        $parents = [];

        foreach ($links as $link) {
            $parentId = (int) $link->parent_epc_id;
            $childId = (int) $link->child_epc_id;

            if ($parentId === $childId) {
                continue;
            }

            foreach ($frontier[$childId] ?? [] as $epcId) {
                $parents[$parentId][$epcId] = $epcId;
            }
        }

        return array_map(array_values(...), $parents);
    }

    /**
     * The same predicate as SQL, for queries selecting from `epcs`.
     *
     * The open-link chain is unrolled to the depth limit rather than written as a
     * recursive CTE: a CTE inside a subquery may not reference the outer row, and
     * pharmaceutical hierarchies are item/case/pallet deep, not arbitrary.
     *
     * @param  string  $epcIdExpression  column naming the EPC to test
     * @return array{0: string, 1: list<string>} raw SQL and its bindings
     */
    public static function ancestorCondition(string $epcIdExpression = 'epcs.id'): array
    {
        $joins = '';
        $handedOff = [];
        $bindings = [];

        for ($level = 1; $level <= self::depthLimit(); $level++) {
            $alias = 'anc'.$level;

            if ($level > 1) {
                $below = 'anc'.($level - 1);
                $joins .=
                    "\n                    LEFT JOIN aggregation_links {$alias}".
                    " ON {$alias}.child_epc_id = {$below}.parent_epc_id".
                    " AND {$alias}.valid_to IS NULL";
            }

            [$levelSql, $levelBindings] = self::parentInTransitCondition($alias);

            $handedOff[] = $levelSql;
            $bindings = [...$bindings, ...$levelBindings];
        }

        $sql = "EXISTS (
                    SELECT 1
                    FROM aggregation_links anc1{$joins}
                    WHERE anc1.child_epc_id = {$epcIdExpression}
                      AND anc1.valid_to IS NULL
                      AND (
                          ".implode("\n                          OR ", $handedOff).'
                      )
                )';

        return [$sql, $bindings];
    }

    /**
     * Whether the container on one rung of the chain is in transit. A rung beyond
     * the end of a shallow hierarchy joins to NULL and matches nothing, so the
     * unrolled depth costs only the rungs a unit actually has.
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function parentInTransitCondition(string $linkAlias): array
    {
        $eventAlias = $linkAlias.'ev';
        $eventEpcAlias = $linkAlias.'ee';

        [$latestSql, $latestBindings] = ResolveEpcLastKnownGln::latestTrackableEventCondition(
            $eventAlias,
            $eventEpcAlias,
        );
        [$inTransitSql, $inTransitBindings] = OutboundShipmentInTransit::eventCondition($eventAlias);

        $sql = "EXISTS (
                              SELECT 1
                              FROM event_epcs {$eventEpcAlias}
                              INNER JOIN epcis_events {$eventAlias}
                                  ON {$eventAlias}.id = {$eventEpcAlias}.event_id
                              WHERE {$eventEpcAlias}.epc_id = {$linkAlias}.parent_epc_id
                                AND {$latestSql}
                                AND {$inTransitSql}
                          )";

        return [$sql, [...$latestBindings, ...$inTransitBindings]];
    }

    private static function depthLimit(): int
    {
        return max(1, (int) config('tracepharma.epcis.validation.hierarchy_depth_limit', 6));
    }
}
