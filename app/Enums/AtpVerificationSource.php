<?php

namespace App\Enums;

/**
 * Where the evidence for a partner's authorized trading partner status came from.
 *
 * DSCSA leaves the method of verification to the buyer, so the record has to name it:
 * a DECRS plant lookup, an FDA WDD/3PL registry lookup, and a state board lookup age
 * differently, and a document the partner sent us is only as good as the day it was issued.
 */
enum AtpVerificationSource: string
{
    case FdaDecrs = 'fda_decrs';
    case FdaWdd3pl = 'fda_wdd3pl';
    case StateBoard = 'state_board';
    case PartnerDocument = 'partner_document';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::FdaDecrs => 'FDA DECRS',
            self::FdaWdd3pl => 'FDA WDD / 3PL registry',
            self::StateBoard => 'State board of pharmacy',
            self::PartnerDocument => 'Partner-supplied document',
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
