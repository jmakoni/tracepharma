<?php

namespace App\Support\Custody;

use App\Enums\EpcisAuthoredKind;

/**
 * "This unit has left the building": recognizes an EPCIS event as one of our own
 * handoff events with the unit still in transit — a shipment to a partner, or an
 * intracompany transfer between two of our own sites.
 *
 * GLN membership alone cannot tell shipped stock from stock on hand. Shipping
 * events authored before ship-to became the bizLocation carry the ship-from
 * (tenant) GLN, so custody checks and shippable-inventory listings would keep
 * reporting shipped units as ours. Both callers negate this predicate — one in
 * PHP against {@see ResolveEpcLastKnownGln::latestEventMeta()}, one in SQL — so
 * the definition lives here once.
 *
 * A transfer stays ours as an organization, but the goods are on a truck: they
 * are at neither site, so nothing may be shipped, packed or unpacked against
 * them until the destination authors its receiving event.
 */
final class OutboundShipmentInTransit
{
    public const EVENT_TYPE = 'ObjectEvent';

    public const BIZ_STEP_NEEDLE = 'shipping';

    public const DISPOSITION_NEEDLE = 'in_transit';

    /**
     * Authored kinds whose shipping event is a handoff: stock leaving for a
     * partner, and stock leaving one of our sites for another.
     *
     * @var list<EpcisAuthoredKind>
     */
    private const IN_TRANSIT_AUTHORED_KINDS = [
        EpcisAuthoredKind::Shipping,
        EpcisAuthoredKind::Transferring,
    ];

    /**
     * Markers the authored_kind backfill used for shipping and transfer
     * documents authored before the column existed.
     *
     * @var list<string>
     */
    private const LEGACY_NOTE_NEEDLES = [
        'Generated outbound shipping',
        'ship order session',
        'Generated transferring',
    ];

    /**
     * Whether an EPC's latest trackable event says we handed it off — shipped to a
     * partner, or in transit between two of our own sites.
     *
     * @param  array{
     *     event_type?: ?string,
     *     biz_step?: ?string,
     *     disposition?: ?string,
     *     document_direction?: ?string,
     *     authored_kind?: ?string,
     *     document_notes?: ?string
     * }|null  $meta
     */
    public static function matches(?array $meta): bool
    {
        if ($meta === null) {
            return false;
        }

        if (($meta['event_type'] ?? null) !== self::EVENT_TYPE) {
            return false;
        }

        if (! self::contains($meta['biz_step'] ?? null, self::BIZ_STEP_NEEDLE)) {
            return false;
        }

        if (! self::contains($meta['disposition'] ?? null, self::DISPOSITION_NEEDLE)) {
            return false;
        }

        return self::isAuthoredHandoffDocument($meta);
    }

    /**
     * Whether the event sits on a shipment or transfer document we authored.
     * Disposition is ignored — a void_shipping event on that document still
     * belongs to us, even though it is no longer in transit.
     *
     * @param  array{
     *     document_direction?: ?string,
     *     authored_kind?: ?string,
     *     document_notes?: ?string
     * }  $meta
     */
    public static function isAuthoredHandoffDocument(array $meta): bool
    {
        if (($meta['document_direction'] ?? null) !== 'outbound') {
            return false;
        }

        $authoredKind = $meta['authored_kind'] ?? null;

        if ($authoredKind !== null) {
            return in_array($authoredKind, self::authoredKindValues(), true);
        }

        $notes = (string) ($meta['document_notes'] ?? '');

        foreach (self::LEGACY_NOTE_NEEDLES as $needle) {
            if (str_contains($notes, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The same predicate as SQL, for queries joining `epcis_events`.
     *
     * COALESCE keeps every comparison non-null so callers can safely wrap the
     * fragment in NOT(...) — three-valued logic would otherwise drop rows whose
     * bizStep or disposition is null.
     *
     * @param  string  $eventAlias  alias of the joined `epcis_events` row
     * @return array{0: string, 1: list<string>} raw SQL and its bindings
     */
    public static function eventCondition(string $eventAlias = 'ev'): array
    {
        [$documentSql, $documentBindings] = self::authoredHandoffDocumentCondition($eventAlias);

        $sql = "(
                    {$eventAlias}.event_type = ?
                    AND COALESCE({$eventAlias}.biz_step, '') LIKE ?
                    AND COALESCE({$eventAlias}.disposition, '') LIKE ?
                    AND {$documentSql}
                )";

        $bindings = [
            self::EVENT_TYPE,
            '%'.self::BIZ_STEP_NEEDLE.'%',
            '%'.self::DISPOSITION_NEEDLE.'%',
            ...$documentBindings,
        ];

        return [$sql, $bindings];
    }

    /**
     * Document-side of an authored shipment or transfer, for callers that must
     * recognize our handoff documents even when the latest event is no longer
     * in transit (void_shipping).
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function authoredHandoffDocumentCondition(string $eventAlias = 'ev'): array
    {
        $legacyNotes = implode(' OR ', array_fill(
            0,
            count(self::LEGACY_NOTE_NEEDLES),
            "COALESCE(shipdoc.notes, '') LIKE ?",
        ));

        $authoredKinds = implode(', ', array_fill(0, count(self::IN_TRANSIT_AUTHORED_KINDS), '?'));

        $sql = "EXISTS (
                        SELECT 1
                        FROM epcis_documents shipdoc
                        WHERE shipdoc.id = {$eventAlias}.document_id
                          AND shipdoc.direction = 'outbound'
                          AND (
                              shipdoc.authored_kind IN ({$authoredKinds})
                              OR (shipdoc.authored_kind IS NULL AND ({$legacyNotes}))
                          )
                    )";

        $bindings = [
            ...self::authoredKindValues(),
            ...array_map(static fn (string $needle): string => '%'.$needle.'%', self::LEGACY_NOTE_NEEDLES),
        ];

        return [$sql, $bindings];
    }

    /**
     * @return list<string>
     */
    private static function authoredKindValues(): array
    {
        return array_map(
            static fn (EpcisAuthoredKind $kind): string => $kind->value,
            self::IN_TRANSIT_AUTHORED_KINDS,
        );
    }

    private static function contains(?string $value, string $needle): bool
    {
        return $value !== null && str_contains(strtolower($value), $needle);
    }
}
