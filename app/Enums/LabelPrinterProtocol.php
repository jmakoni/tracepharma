<?php

namespace App\Enums;

enum LabelPrinterProtocol: string
{
    case ZplRaw = 'zpl_raw';
    case QzTray = 'qz_tray';
    case ZebraBrowserPrint = 'zebra_browser_print';

    public function label(): string
    {
        return match ($this) {
            self::ZplRaw => 'ZPL (TCP raw / network)',
            self::QzTray => 'QZ Tray (local workstation)',
            self::ZebraBrowserPrint => 'Zebra Browser Print (local workstation)',
        };
    }

    public function defaultPort(): int
    {
        return match ($this) {
            self::ZplRaw => 9100,
            self::QzTray, self::ZebraBrowserPrint => 0,
        };
    }

    public function isClientSide(): bool
    {
        return $this !== self::ZplRaw;
    }

    public function requiresNetworkAddress(): bool
    {
        return $this === self::ZplRaw;
    }
}
