<?php

namespace App\Enums;

/**
 * How workstation label jobs are delivered when the site is behind a firewall.
 */
enum ClientPrintBridge: string
{
    case NetworkTcp = 'network_tcp';
    case QzTray = 'qz_tray';
    case ZebraBrowserPrint = 'zebra_browser_print';

    public function label(): string
    {
        return match ($this) {
            self::NetworkTcp => 'Network TCP (server → printer :9100)',
            self::QzTray => 'QZ Tray (local agent — USB/firewall OK)',
            self::ZebraBrowserPrint => 'Zebra Browser Print (local agent — Zebra USB/firewall OK)',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::NetworkTcp => 'Network TCP',
            self::QzTray => 'QZ Tray',
            self::ZebraBrowserPrint => 'Zebra Browser Print',
        };
    }

    public function isClientSide(): bool
    {
        return $this !== self::NetworkTcp;
    }

    public function toPrinterProtocol(): LabelPrinterProtocol
    {
        return match ($this) {
            self::NetworkTcp => LabelPrinterProtocol::ZplRaw,
            self::QzTray => LabelPrinterProtocol::QzTray,
            self::ZebraBrowserPrint => LabelPrinterProtocol::ZebraBrowserPrint,
        };
    }

    public static function tryFromMixed(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        return self::tryFrom($value);
    }
}
