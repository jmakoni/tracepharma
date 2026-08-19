<?php

namespace App\Support\Custody;

use App\Models\Epcis\Epc;
use App\Support\Gs1\Sgln;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Last-known location GLN for an EPC, from its latest trackable event.
 *
 * "Trackable" means an ObjectEvent or AggregationEvent that says something about
 * where the EPC is: it carries a readPoint/bizLocation GLN, or its bizStep is a
 * shipping/receiving step. The preferred GLN is bizLocation (where the EPC came
 * to rest) falling back to readPoint (where it was scanned).
 *
 * Custody checks and shippable-inventory listings both hang off these semantics,
 * so the correlated "latest event" condition is exposed as reusable SQL rather
 * than duplicated per caller ({@see latestTrackableEventCondition()}).
 *
 * A GLN is not the whole story — an outbound shipping event leaves the unit in
 * transit even when the GLN still reads as ours — so the same lookup also yields
 * the event's bizStep, disposition and authoring document
 * ({@see latestEventMeta()}, consumed by {@see OutboundShipmentInTransit}).
 */
final class ResolveEpcLastKnownGln
{
    /** @var list<string> */
    public const TRACKABLE_EVENT_TYPES = ['ObjectEvent', 'AggregationEvent'];

    /** @var list<string> */
    private const LOCATION_BIZ_STEP_PATTERNS = ['%shipping%', '%receiving%'];

    /**
     * Normalized last-known GLN, or null when the EPC has no trackable event
     * (or its latest trackable event carries no location).
     */
    public function forEpc(Epc|int $epc): ?string
    {
        $epcId = $epc instanceof Epc ? (int) $epc->getKey() : $epc;

        return $this->forEpcIds([$epcId])[$epcId] ?? null;
    }

    /**
     * Last-known GLNs for many EPCs in one query.
     *
     * @param  iterable<Epc|int>  $epcs
     * @return array<int, string|null> keyed by EPC id; every resolvable id requested is present
     */
    public function forEpcIds(iterable $epcs): array
    {
        return array_map(
            static fn (?array $meta): ?string => $meta['gln'] ?? null,
            $this->latestEventMetaForEpcIds($epcs),
        );
    }

    /**
     * The EPC's latest trackable event, with the bits custody decisions need:
     * where it left the EPC and what the event says it was doing there.
     *
     * @return array{
     *     gln: ?string,
     *     event_type: ?string,
     *     biz_step: ?string,
     *     disposition: ?string,
     *     document_direction: ?string,
     *     authored_kind: ?string,
     *     document_notes: ?string
     * }|null null when the EPC has no trackable event
     */
    public function latestEventMeta(Epc|int $epc): ?array
    {
        $epcId = $epc instanceof Epc ? (int) $epc->getKey() : $epc;

        return $this->latestEventMetaForEpcIds([$epcId])[$epcId] ?? null;
    }

    /**
     * Latest trackable event metadata for many EPCs in one query.
     *
     * @param  iterable<Epc|int>  $epcs
     * @return array<int, array{
     *     gln: ?string,
     *     event_type: ?string,
     *     biz_step: ?string,
     *     disposition: ?string,
     *     document_direction: ?string,
     *     authored_kind: ?string,
     *     document_notes: ?string
     * }|null> keyed by EPC id; every id requested is present
     */
    public function latestEventMetaForEpcIds(iterable $epcs): array
    {
        $epcIds = self::normalizeEpcIds($epcs);

        if ($epcIds === []) {
            return [];
        }

        $resolved = array_fill_keys($epcIds, null);

        [$latestSql, $latestBindings] = self::latestTrackableEventCondition();

        $rows = DB::table('event_epcs as ee')
            ->join('epcis_events as ev', 'ev.id', '=', 'ee.event_id')
            ->leftJoin('epcis_documents as doc', 'doc.id', '=', 'ev.document_id')
            ->whereIn('ee.epc_id', $epcIds)
            ->whereIn('ev.event_type', self::TRACKABLE_EVENT_TYPES)
            ->where(fn ($location) => self::applyHasTrackableLocation($location))
            // A document that errored or was voided may still carry partial events from a
            // failed/retracted ingest — those never represented a confirmed custody move,
            // so they must not win "latest trackable event" over a prior good one.
            ->where(fn ($status) => $status->whereNull('doc.status')->orWhereNotIn('doc.status', ['error', 'voided']))
            ->whereRaw($latestSql, $latestBindings)
            ->get([
                'ee.epc_id',
                'ev.event_type',
                'ev.biz_step',
                'ev.disposition',
                'ev.read_point_gln',
                'ev.biz_location_gln',
                'doc.direction as document_direction',
                'doc.authored_kind',
                'doc.notes as document_notes',
            ]);

        foreach ($rows as $row) {
            $epcId = (int) $row->epc_id;

            if (! array_key_exists($epcId, $resolved)) {
                continue;
            }

            $resolved[$epcId] = [
                'gln' => self::preferredGln(
                    self::nullableString($row->biz_location_gln),
                    self::nullableString($row->read_point_gln),
                ),
                'event_type' => self::nullableString($row->event_type),
                'biz_step' => self::nullableString($row->biz_step),
                'disposition' => self::nullableString($row->disposition),
                'document_direction' => self::nullableString($row->document_direction),
                'authored_kind' => self::nullableString($row->authored_kind),
                'document_notes' => self::nullableString($row->document_notes),
            ];
        }

        return $resolved;
    }

    /**
     * Correlated condition restricting an `epcis_events` row to the EPC's latest
     * trackable event, for callers joining `event_epcs` to `epcis_events`.
     *
     * Events carrying a GLN outrank bizStep-only events regardless of age: a partner's
     * shipping event with no readPoint/bizLocation says the EPC moved but not where to,
     * and letting it win would erase the dock GLN that proves custody. Only when no
     * event carries a location at all does the newest bizStep-only event answer.
     *
     * @param  string  $eventAlias  alias of the joined `epcis_events` row
     * @param  string  $eventEpcAlias  alias of the joined `event_epcs` row
     * @return array{0: string, 1: list<string>} raw SQL and its bindings
     */
    public static function latestTrackableEventCondition(
        string $eventAlias = 'ev',
        string $eventEpcAlias = 'ee',
    ): array {
        $typePlaceholders = implode(', ', array_fill(0, count(self::TRACKABLE_EVENT_TYPES), '?'));

        $bizStepClauses = implode("\n                          ", array_fill(
            0,
            count(self::LOCATION_BIZ_STEP_PATTERNS),
            'OR ev2.biz_step LIKE ?',
        ));

        $sql = "{$eventAlias}.id = (
                    SELECT ev2.id
                    FROM event_epcs ee2
                    INNER JOIN epcis_events ev2 ON ev2.id = ee2.event_id
                    LEFT JOIN epcis_documents doc2 ON doc2.id = ev2.document_id
                    WHERE ee2.epc_id = {$eventEpcAlias}.epc_id
                      AND ev2.event_type IN ({$typePlaceholders})
                      AND (doc2.status IS NULL OR doc2.status NOT IN ('error', 'voided'))
                      AND (
                          ev2.read_point_gln IS NOT NULL
                          OR ev2.biz_location_gln IS NOT NULL
                          {$bizStepClauses}
                      )
                    ORDER BY (
                        ev2.read_point_gln IS NOT NULL
                        OR ev2.biz_location_gln IS NOT NULL
                    ) DESC, ev2.event_time DESC, ev2.id DESC
                    LIMIT 1
                )";

        return [
            $sql,
            [...self::TRACKABLE_EVENT_TYPES, ...self::LOCATION_BIZ_STEP_PATTERNS],
        ];
    }

    /**
     * Constrain a query to events that say something about location — a GLN on
     * readPoint/bizLocation, or a shipping/receiving bizStep.
     *
     * @param  EloquentBuilder<*>|QueryBuilder  $query
     */
    public static function applyHasTrackableLocation(
        EloquentBuilder|QueryBuilder $query,
        string $eventAlias = 'ev',
    ): EloquentBuilder|QueryBuilder {
        $query->whereNotNull("{$eventAlias}.read_point_gln")
            ->orWhereNotNull("{$eventAlias}.biz_location_gln");

        foreach (self::LOCATION_BIZ_STEP_PATTERNS as $pattern) {
            $query->orWhere("{$eventAlias}.biz_step", 'like', $pattern);
        }

        return $query;
    }

    /**
     * bizLocation wins over readPoint: it is where the EPC came to rest.
     */
    public static function preferredGln(?string $bizLocationGln, ?string $readPointGln): ?string
    {
        return Sgln::normalizeGln($bizLocationGln) ?? Sgln::normalizeGln($readPointGln);
    }

    /**
     * The same preference as SQL, for queries that must ask "where did this event
     * leave the EPC?" of a row rather than of a loaded meta array.
     *
     * NULLIF matches {@see preferredGln()}, where a blank bizLocation normalizes to
     * null and falls through to readPoint. Callers compare the result against an
     * already-normalized 13-digit GLN, which is the only form the columns hold.
     *
     * @param  string  $eventAlias  alias of the `epcis_events` row
     */
    public static function preferredGlnExpression(string $eventAlias = 'ev'): string
    {
        return "COALESCE(NULLIF({$eventAlias}.biz_location_gln, ''), {$eventAlias}.read_point_gln)";
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value !== null ? (string) $value : null;
    }

    /**
     * @param  iterable<Epc|int>  $epcs
     * @return list<int>
     */
    private static function normalizeEpcIds(iterable $epcs): array
    {
        $epcIds = [];

        foreach ($epcs as $epc) {
            $epcId = $epc instanceof Epc ? (int) $epc->getKey() : (int) $epc;

            if ($epcId > 0) {
                $epcIds[$epcId] = true;
            }
        }

        return array_keys($epcIds);
    }
}
