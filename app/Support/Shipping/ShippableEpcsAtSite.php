<?php

namespace App\Support\Shipping;

use App\Models\Epcis\Epc;
use App\Models\Site;
use App\Support\Custody\InTransitInsideOpenParent;
use App\Support\Custody\OutboundShipmentInTransit;
use App\Support\Custody\ResolveEpcLastKnownGln;
use App\Support\Custody\TerminalEpcDisposition;
use App\Support\Custody\UnreceivedPartnerShipment;
use App\Support\Gs1\Sgln;
use Illuminate\Database\Eloquent\Builder;

/**
 * EPC ids whose last-known location is at a site (by GLN on the latest
 * trackable ObjectEvent or AggregationEvent — shipping, receiving, or
 * readPoint/bizLocation match), excluding stock that is not ours to pick: shipped
 * to a partner, in transit to another of our sites on an intracompany transfer,
 * announced by a supplier but not yet received, or retired by a terminal
 * disposition.
 *
 * Effective last-known includes open-parent co-location
 * ({@see ResolveEpcLastKnownGln}): packed children follow the outermost SSCC after
 * a transfer/partner receive that names the container only. {@see contains()} and
 * {@see filter()} use that PHP path; {@see query()} mirrors it in SQL so site
 * inventory listings stay consistent.
 *
 * ShipOrder uses this to list shippable inventory at a ship-from site.
 * Last-event semantics are shared with custody checks via
 * {@see ResolveEpcLastKnownGln}, down to which GLN on the event answers for the
 * unit's location; this class adds the ship-from GLN filter and the exclusions:
 * in-transit stock ({@see OutboundShipmentInTransit}), which reaches packed
 * contents through their container ({@see InTransitInsideOpenParent}), inbound
 * shipments nobody has received yet ({@see UnreceivedPartnerShipment}), and
 * destroyed, recalled or decommissioned units
 * ({@see TerminalEpcDisposition}).
 */
final class ShippableEpcsAtSite
{
    public function __construct(
        private readonly ResolveEpcLastKnownGln $lastKnownGln,
        private readonly InTransitInsideOpenParent $inTransitInsideOpenParent,
    ) {}

    /** @return Builder<Epc> */
    public function query(int $siteId): Builder
    {
        $site = Site::query()->find($siteId);
        $gln = Sgln::normalizeGln($site?->gln);

        if ($gln === null) {
            return Epc::query()->whereRaw('0 = 1');
        }

        [$latestEventSql, $latestEventBindings] = ResolveEpcLastKnownGln::latestTrackableEventCondition();
        [$shippedOutSql, $shippedOutBindings] = OutboundShipmentInTransit::eventCondition();
        [$unreceivedSql, $unreceivedBindings] = UnreceivedPartnerShipment::eventCondition();
        [$retiredSql, $retiredBindings] = TerminalEpcDisposition::eventCondition();
        [$inContainerSql, $inContainerBindings] = InTransitInsideOpenParent::ancestorCondition();
        [$coLocatedSql, $coLocatedBindings] = self::openParentAtGlnCondition($gln);
        [$parentElsewhereSql, $parentElsewhereBindings] = self::openParentElsewhereCondition($gln);

        return Epc::query()
            ->where(function ($location) use (
                $gln,
                $latestEventSql,
                $latestEventBindings,
                $shippedOutSql,
                $shippedOutBindings,
                $unreceivedSql,
                $unreceivedBindings,
                $retiredSql,
                $retiredBindings,
                $coLocatedSql,
                $coLocatedBindings,
                $parentElsewhereSql,
                $parentElsewhereBindings,
            ): void {
                // Own latest event at this site, and no open parent already came to rest elsewhere
                // (transfer receive of the SSCC must not leave packed children pickable at origin).
                $location->where(function ($own) use (
                    $gln,
                    $latestEventSql,
                    $latestEventBindings,
                    $shippedOutSql,
                    $shippedOutBindings,
                    $unreceivedSql,
                    $unreceivedBindings,
                    $retiredSql,
                    $retiredBindings,
                    $parentElsewhereSql,
                    $parentElsewhereBindings,
                ): void {
                    $own->whereExists(function ($exists) use (
                        $gln,
                        $latestEventSql,
                        $latestEventBindings,
                        $shippedOutSql,
                        $shippedOutBindings,
                        $unreceivedSql,
                        $unreceivedBindings,
                        $retiredSql,
                        $retiredBindings,
                    ): void {
                        $exists->selectRaw('1')
                            ->from('event_epcs as ee')
                            ->join('epcis_events as ev', 'ev.id', '=', 'ee.event_id')
                            ->whereColumn('ee.epc_id', 'epcs.id')
                            ->whereIn('ev.event_type', ResolveEpcLastKnownGln::TRACKABLE_EVENT_TYPES)
                            ->where(fn ($loc) => ResolveEpcLastKnownGln::applyHasTrackableLocation($loc))
                            ->whereRaw(ResolveEpcLastKnownGln::preferredGlnExpression().' = ?', [$gln])
                            ->whereRaw($latestEventSql, $latestEventBindings)
                            ->whereRaw("NOT {$shippedOutSql}", $shippedOutBindings)
                            ->whereRaw("NOT {$unreceivedSql}", $unreceivedBindings)
                            ->whereRaw("NOT {$retiredSql}", $retiredBindings);
                    })->whereRaw("NOT {$parentElsewhereSql}", $parentElsewhereBindings);
                })->orWhereRaw($coLocatedSql, $coLocatedBindings);
            })
            // Contents ride out on their container's event and keep an aggregation event
            // at the dock as their own latest. Asked after the location filter, so only
            // stock that reads as on hand here pays for the climb.
            ->whereRaw("NOT {$inContainerSql}", $inContainerBindings);
    }

    /**
     * Whether one EPC is shippable at the site.
     *
     * Uses effective last-known (open-parent co-location) so packed children follow
     * an SSCC received at another site — the same gate break-pack / pack / unpack use.
     */
    public function contains(int $siteId, int $epcId): bool
    {
        if ($epcId <= 0) {
            return false;
        }

        return $this->filter($siteId, [$epcId]) !== [];
    }

    /**
     * Which of the given EPCs are shippable at the site.
     *
     * For callers holding a known set — the confirmed lines of a receiving session
     * being copied onto a ship order — where {@see epcIds()} would materialize the
     * site's whole on-hand inventory to answer about a handful of units.
     *
     * @param  iterable<int>  $epcIds
     * @return list<int>
     */
    public function filter(int $siteId, iterable $epcIds): array
    {
        $candidateIds = [];

        foreach ($epcIds as $epcId) {
            $epcId = (int) $epcId;

            if ($epcId > 0) {
                $candidateIds[$epcId] = true;
            }
        }

        if ($candidateIds === []) {
            return [];
        }

        $site = Site::query()->find($siteId);
        $gln = Sgln::normalizeGln($site?->gln);

        if ($gln === null) {
            return [];
        }

        $ids = array_keys($candidateIds);
        $metas = $this->lastKnownGln->latestEventMetaForEpcIds($ids);
        $inTransitAncestors = $this->inTransitInsideOpenParent->inTransitAncestorByEpcId($ids);

        $matched = [];

        foreach ($ids as $epcId) {
            if (isset($inTransitAncestors[$epcId])) {
                continue;
            }

            $meta = $metas[$epcId] ?? null;

            if ($meta === null
                || TerminalEpcDisposition::matches($meta)
                || OutboundShipmentInTransit::matches($meta)
                || UnreceivedPartnerShipment::matches($meta)) {
                continue;
            }

            if (($meta['gln'] ?? null) === $gln) {
                $matched[] = $epcId;
            }
        }

        sort($matched);

        return $matched;
    }

    /** @return list<int> */
    public function epcIds(int $siteId): array
    {
        return $this->query($siteId)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Open aggregation ancestor at any depth whose own latest preferred GLN is this
     * site and who is not outbound in-transit (co-located packed contents).
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function openParentAtGlnCondition(string $gln, string $epcIdExpression = 'epcs.id'): array
    {
        return self::unrolledOpenParentCondition(
            $epcIdExpression,
            atGln: $gln,
            elsewhere: false,
        );
    }

    /**
     * Open aggregation ancestor came to rest at a different GLN — packed contents
     * must not stay pickable on their own origin event.
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function openParentElsewhereCondition(string $gln, string $epcIdExpression = 'epcs.id'): array
    {
        return self::unrolledOpenParentCondition(
            $epcIdExpression,
            atGln: $gln,
            elsewhere: true,
        );
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private static function unrolledOpenParentCondition(
        string $epcIdExpression,
        string $atGln,
        bool $elsewhere,
    ): array {
        $joins = '';
        $levelMatches = [];
        $bindings = [];

        $depthLimit = max(1, (int) config('tracepharma.epcis.validation.hierarchy_depth_limit', 6));

        for ($level = 1; $level <= $depthLimit; $level++) {
            $alias = 'col'.$level;

            if ($level > 1) {
                $below = 'col'.($level - 1);
                $joins .=
                    "\n                    LEFT JOIN aggregation_links {$alias}".
                    " ON {$alias}.child_epc_id = {$below}.parent_epc_id".
                    " AND {$alias}.valid_to IS NULL";
            }

            [$levelSql, $levelBindings] = self::parentLocationCondition($alias, $atGln, $elsewhere);

            // Co-locate / elsewhere only against the outermost open ancestor: a mid-level
            // case still packed at origin must not keep items "at origin" after the pallet
            // received elsewhere (matches ResolveEpcLastKnownGln outermost climb).
            if ($elsewhere) {
                $levelMatches[] = $levelSql;
            } elseif ($level < $depthLimit) {
                $next = 'col'.($level + 1);
                // next alias is joined in the following iteration; for the predicate we
                // require no open parent above this level (next join null).
                $levelMatches[] = "({$levelSql} AND {$next}.parent_epc_id IS NULL)";
            } else {
                $levelMatches[] = $levelSql;
            }

            $bindings = [...$bindings, ...$levelBindings];
        }

        $sql = "EXISTS (
                    SELECT 1
                    FROM aggregation_links col1{$joins}
                    WHERE col1.child_epc_id = {$epcIdExpression}
                      AND col1.valid_to IS NULL
                      AND (
                          ".implode("\n                          OR ", $levelMatches).'
                      )
                )';

        return [$sql, $bindings];
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private static function parentLocationCondition(string $linkAlias, string $atGln, bool $elsewhere): array
    {
        $eventAlias = $linkAlias.'ev';
        $eventEpcAlias = $linkAlias.'ee';

        [$latestSql, $latestBindings] = ResolveEpcLastKnownGln::latestTrackableEventCondition(
            $eventAlias,
            $eventEpcAlias,
        );
        [$inTransitSql, $inTransitBindings] = OutboundShipmentInTransit::eventCondition($eventAlias);

        $glnExpr = ResolveEpcLastKnownGln::preferredGlnExpression($eventAlias);
        $glnPredicate = $elsewhere
            ? "{$glnExpr} IS NOT NULL AND {$glnExpr} <> ?"
            : "{$glnExpr} = ?";

        // Elsewhere / at-site both require the parent not to be outbound in-transit:
        // in-transit is handled by InTransitInsideOpenParent on the outer query.
        $sql = "EXISTS (
                              SELECT 1
                              FROM event_epcs {$eventEpcAlias}
                              INNER JOIN epcis_events {$eventAlias}
                                  ON {$eventAlias}.id = {$eventEpcAlias}.event_id
                              WHERE {$eventEpcAlias}.epc_id = {$linkAlias}.parent_epc_id
                                AND {$latestSql}
                                AND {$glnPredicate}
                                AND NOT {$inTransitSql}
                          )";

        return [$sql, [...$latestBindings, $atGln, ...$inTransitBindings]];
    }
}
