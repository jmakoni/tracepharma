<?php

namespace App\Enums;

/**
 * Where the evidence for a partner's authorized trading partner status came from.
 *
 * DSCSA leaves the method of verification to the buyer, so the record has to name it:
 * a DECRS plant lookup, an FDA WDD/3PL registry lookup, and a state board lookup age
 * differently, and a document the partner sent us is only as good as the day it was issued.
 * Pulse / OCI cases are manual partner-supplied evidence only — not API sync, not Pulse-listed.
 */
enum AtpVerificationSource: string
{
    case FdaDecrs = 'fda_decrs';
    case FdaWdd3pl = 'fda_wdd3pl';
    case StateBoard = 'state_board';
    case PartnerDocument = 'partner_document';
    /** Manual partner-supplied NABP Pulse screenshot / profile URL — not a Pulse API sync. */
    case PulsePartnerEvidence = 'pulse_partner_evidence';
    /** Manual partner-supplied OCI / directory evidence — not an OCI API integration. */
    case OciPartnerEvidence = 'oci_partner_evidence';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::FdaDecrs => 'FDA DECRS',
            self::FdaWdd3pl => 'FDA WDD / 3PL registry',
            self::StateBoard => 'State board of pharmacy',
            self::PartnerDocument => 'Partner-supplied document',
            self::PulsePartnerEvidence => 'NABP Pulse (partner-supplied evidence)',
            self::OciPartnerEvidence => 'OCI / directory (partner-supplied evidence)',
            self::Other => 'Other',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $source): array => [$source->value => $source->label()])
            ->all();
    }
}
