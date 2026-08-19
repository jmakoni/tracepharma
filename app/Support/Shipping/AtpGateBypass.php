<?php

namespace App\Support\Shipping;

use App\Actions\Shipping\GenerateShippingEpcisEvents;
use App\Actions\Shipping\ValidateOutboundShippingSend;
use App\Models\Epcis\EpcisDocument;

/**
 * How a shipment authored while the outbound ATP gate was down says so.
 *
 * The gate is a compliance kill-switch: with it off, {@see ValidateOutboundShippingSend}
 * lets a send through without checking the destination's ATP licence. The operator sees a
 * banner at the time, but the document outlives the banner and the config value it read —
 * and an inspector asking why this shipment went to an unlicensed address would otherwise
 * find a clean record indistinguishable from one that passed the check.
 */
final class AtpGateBypass
{
    /**
     * Sentence {@see GenerateShippingEpcisEvents} writes into the notes of a document
     * authored with the gate down.
     */
    public const NOTE_MARKER = 'ATP outbound gate bypassed.';

    /**
     * Whether the gate is lifted right now, i.e. this send is going out unverified.
     */
    public static function isBypassed(): bool
    {
        return ! config('tracepharma.epcis.enforce_atp_outbound_gate', true);
    }

    public static function stampedOn(EpcisDocument $document): bool
    {
        return str_contains((string) $document->notes, self::NOTE_MARKER);
    }
}
