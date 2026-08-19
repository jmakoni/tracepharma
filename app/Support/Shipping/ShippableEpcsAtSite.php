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

        return Epc::query()
            ->whereExists(function ($exists) use (
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
                    ->where(fn ($location) => ResolveEpcLastKnownGln::applyHasTrackableLocation($location))
                    // The same GLN custody reads off the event: bizLocation is where the
                    // unit came to rest, so an event that names a destination — a partner
                    // shipment carries ship-to as its bizLocation and our dock as its
                    // readPoint — leaves nothing on hand here.
                    ->whereRaw(ResolveEpcLastKnownGln::preferredGlnExpression().' = ?', [$gln])
                    ->whereRaw($latestEventSql, $latestEventBindings)
                    // A shipping event — partner shipment or intracompany transfer — keeps the
                    // origin site as its readPoint, so stock that has left would otherwise still
                    // list as on hand at the dock it left from.
                    ->whereRaw("NOT {$shippedOutSql}", $shippedOutBindings)
                    // A supplier's shipment often names the ship-to dock as its bizLocation,
                    // which reads as here. Stock is pickable once the floor receives it, not
                    // when a partner announces it.
                    ->whereRaw("NOT {$unreceivedSql}", $unreceivedBindings)
                    // A destroy or recall is read at our own dock, so the unit reads as here
                    // and must still never reach a pick list.
                    ->whereRaw("NOT {$retiredSql}", $retiredBindings);
            })
            // Contents ride out on their container's event and keep an aggregation event
            // at the dock as their own latest. Asked after the location filter, so only
            // stock that reads as on hand here pays for the climb.
            ->whereRaw("NOT {$inContainerSql}", $inContainerBindings);
    }

    /**
     * Whether one EPC is shippable at the site.
     *
     * Per-scan callers use this instead of {@see epcIds()}: the same correlated
     * last-event condition runs as an EXISTS for a single row, rather than
     * materializing every shippable id at the site to test membership.
     */
    public function contains(int $siteId, int $epcId): bool
    {
        if ($epcId <= 0) {
            return false;
        }

        return $this->query($siteId)->whereKey($epcId)->exists();
    }

    /**
     * Which of the given EPCs are shippable at the site, in one query.
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

        return $this->query($siteId)
            ->whereIn('id', array_keys($candidateIds))
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
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
}
