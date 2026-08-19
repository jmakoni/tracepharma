<?php

declare(strict_types=1);

namespace App\Enums;

enum ReceivingSessionKind: string
{
    case InboundAsn = 'inbound_asn';
    case ScanFirst = 'scan_first';
    case TransferReceive = 'transfer_receive';

    public function label(): string
    {
        return match ($this) {
            self::InboundAsn => 'ASN receive',
            self::ScanFirst => 'Scan-first',
            self::TransferReceive => 'Transfer receive',
        };
    }

    /**
     * Compact list/HUD badge labels.
     */
    public function badgeLabel(): string
    {
        return match ($this) {
            self::InboundAsn => 'ASN',
            self::ScanFirst => 'Scan-first',
            self::TransferReceive => 'Transfer',
        };
    }
}
