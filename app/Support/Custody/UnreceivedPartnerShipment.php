<?php

namespace App\Support\Custody;

/**
 * "Somebody says it is on its way to us": recognizes an EPCIS event as a shipping
 * event that is not one of our own handoffs — the shipping ObjectEvent on a
 * partner's DSCSA shipment, or on any document we did not author as a shipment or
 * transfer.
 *
 * A GLN on such an event is a claim, not possession. Suppliers routinely put the
 * ship-to dock in bizLocation, so an ASN naming one of our GLNs would otherwise
 * hand us custody of stock nobody has unloaded, and units could be packed, shipped
 * or transferred on before they physically arrived. Stock we did not commission
 * enters our custody when the floor receives it and GenerateReceivingEpcisEvents
 * authors the receiving event, which then becomes the EPC's latest trackable event
 * and answers for where it is.
 *
 * Deliberately complementary to {@see OutboundShipmentInTransit}: that predicate
 * owns the shipping events we authored (a shipment or intracompany transfer in
 * transit), this one owns every other shipping event. Between them, no shipping
 * event ever confers custody — a shipping event is a handoff, and only a receiving
 * or on-site event puts the unit back in someone's hands. Callers test the
 * authored predicate first so shipped stock still gets the "already shipped"
 * refusal instead of the "not received yet" one.
 *
 * The bizStep needle is the loose one {@see ResolveEpcLastKnownGln} and the sibling
 * predicate already use, so a partner's `void_shipping` reads as a shipping step
 * here too — a voided shipment is not a receipt either.
 */
final class UnreceivedPartnerShipment
{
    public const BIZ_STEP_NEEDLE = 'shipping';

    /**
     * Whether an EPC's latest trackable event is somebody else's shipment rather
     * than evidence that the unit is on hand.
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

        $bizStep = $meta['biz_step'] ?? null;

        if ($bizStep === null || ! str_contains(strtolower($bizStep), self::BIZ_STEP_NEEDLE)) {
            return false;
        }

        return ! OutboundShipmentInTransit::matches($meta);
    }

    /**
     * The same predicate as SQL, for queries joining `epcis_events`.
     *
     * COALESCE keeps the bizStep comparison non-null, and the authored fragment is
     * null-safe by construction, so callers may wrap the whole thing in NOT(...).
     *
     * @param  string  $eventAlias  alias of the joined `epcis_events` row
     * @return array{0: string, 1: list<string>} raw SQL and its bindings
     */
    public static function eventCondition(string $eventAlias = 'ev'): array
    {
        [$authoredHandoffSql, $authoredHandoffBindings] = OutboundShipmentInTransit::eventCondition($eventAlias);

        $sql = "(
                    COALESCE({$eventAlias}.biz_step, '') LIKE ?
                    AND NOT {$authoredHandoffSql}
                )";

        return [
            $sql,
            ['%'.self::BIZ_STEP_NEEDLE.'%', ...$authoredHandoffBindings],
        ];
    }
}
