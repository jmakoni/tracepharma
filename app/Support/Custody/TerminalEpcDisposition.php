<?php

namespace App\Support\Custody;

use App\Support\Epcis\Validation\EpcisCatalogBusinessRules;
use App\Support\Epcis\Validation\EpcisCbvAllowlist;

/**
 * "This unit is off the board": recognizes an EPCIS event whose disposition
 * retires the identity — destroyed, disposed, expired, recalled, stolen, or
 * decommissioned (CBV spells the last one `inactive`).
 *
 * A terminal disposition is a stronger statement than a location. The GLN on a
 * destroy event is our own dock, so GLN membership alone keeps reporting the unit
 * as stock on hand: custody checks would wave it onto a shipment and the
 * shippable-inventory listing would offer it for picking, both of which author
 * EPCIS that contradicts an event already on record — the same contradiction
 * {@see EpcisCatalogBusinessRules} raises as DECOMMISSIONED_SERIAL_SHIPPED when a
 * partner's document does it.
 *
 * As with {@see OutboundShipmentInTransit}, one caller negates this predicate in
 * PHP against {@see ResolveEpcLastKnownGln::latestEventMeta()} and one in SQL, so
 * the definition lives here once.
 *
 * Who authored the event does not matter: a partner reporting a recall or a
 * destruction is telling us the unit is gone whether or not we agree, and acting
 * on it anyway is exactly the chain-of-custody break the disposition records.
 * Reversing one is a correction to the event store, not a scan.
 *
 * Dispositions that end a unit's *commercial* life without retiring the identity
 * — `retail_sold`, `dispensed` — are deliberately absent: a sold pack can be
 * returned and shipped again, so those are inventory questions, not custody ones.
 */
final class TerminalEpcDisposition
{
    /**
     * CBV disposition local names that retire the identity.
     *
     * `inactive` is the CBV value paired with a decommissioning bizStep;
     * `decommissioned` is the spelling this codebase already accepts on inbound
     * documents ({@see EpcisCbvAllowlist::DISPOSITIONS}).
     *
     * @var list<string>
     */
    public const DISPOSITIONS = [
        'destroyed',
        'disposed',
        'expired',
        'recalled',
        'stolen',
        'inactive',
        'decommissioned',
    ];

    /**
     * Whether an EPC's latest trackable event retires it.
     *
     * @param  array{disposition?: ?string}|null  $meta
     */
    public static function matches(?array $meta): bool
    {
        return $meta !== null && self::isTerminal($meta['disposition'] ?? null);
    }

    public static function isTerminal(?string $disposition): bool
    {
        return in_array(self::localName($disposition), self::DISPOSITIONS, true);
    }

    /**
     * How a refusal names the state to the operator: the CBV local name, since
     * "destroyed" is what the exception queue and the trace timeline call it too.
     */
    public static function label(?string $disposition): string
    {
        $local = self::localName($disposition);

        return $local === '' ? 'decommissioned' : str_replace('_', ' ', $local);
    }

    /**
     * The same predicate as SQL, for queries joining `epcis_events`.
     *
     * COALESCE keeps the comparison non-null so callers can wrap the fragment in
     * NOT(...) — three-valued logic would otherwise drop rows with no disposition,
     * which is most of them. SUBSTRING_INDEX mirrors {@see localName()}: both the
     * full CBV URI and a bare local name are stored in the wild.
     *
     * @param  string  $eventAlias  alias of the joined `epcis_events` row
     * @return array{0: string, 1: list<string>} raw SQL and its bindings
     */
    public static function eventCondition(string $eventAlias = 'ev'): array
    {
        $placeholders = implode(', ', array_fill(0, count(self::DISPOSITIONS), '?'));

        $sql = "(
                    LOWER(TRIM(SUBSTRING_INDEX(COALESCE({$eventAlias}.disposition, ''), ':', -1)))
                        IN ({$placeholders})
                )";

        return [$sql, self::DISPOSITIONS];
    }

    private static function localName(?string $disposition): string
    {
        if ($disposition === null) {
            return '';
        }

        $value = strtolower(trim($disposition));

        if (str_contains($value, ':')) {
            $value = (string) str($value)->afterLast(':');
        }

        return trim($value);
    }
}
