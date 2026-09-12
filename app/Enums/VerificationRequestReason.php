<?php

declare(strict_types=1);

namespace App\Enums;

enum VerificationRequestReason: string
{
    case BarcodeScanIssue = 'barcode_scan_issue';
    case NotOurProduct = 'not_our_product';
    case Recalled = 'recalled';
    case CounterfeitSuspect = 'counterfeit_suspect';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::BarcodeScanIssue => 'Barcode scan issue',
            self::NotOurProduct => 'Not our product',
            self::Recalled => 'Recalled product',
            self::CounterfeitSuspect => 'Counterfeit suspect',
            self::Other => 'Other',
        };
    }
}
