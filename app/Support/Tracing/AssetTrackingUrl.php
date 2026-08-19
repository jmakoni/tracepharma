<?php

namespace App\Support\Tracing;

use App\Filament\App\Pages\AssetTracking;
use App\Models\Epcis\Epc;
use App\Support\Ui\CopyableIdentifier;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\TextColumn;

/**
 * Build Asset Tracking URLs for instance-level SSCC / SGTIN displays.
 *
 * Bare product-master GTIN (no serial) does not resolve — returns null.
 */
final class AssetTrackingUrl
{
    public static function scanForEpc(?Epc $epc): ?string
    {
        if ($epc === null) {
            return null;
        }

        // Prefer GS1 human-readable / element-string forms over Pure Identity URN.
        $dual = Gs1DualDisplay::forEpc($epc);
        if (filled($dual['gs1_barcode']) && $dual['gs1_barcode'] !== '—') {
            return $dual['gs1_barcode'];
        }

        if (filled($epc->ai_00)) {
            return (string) $epc->ai_00;
        }

        if (filled($epc->sscc18)) {
            return '(00)'.(string) $epc->sscc18;
        }

        if (filled($epc->ai_01_21)) {
            return (string) $epc->ai_01_21;
        }

        if (filled($epc->gtin14) && filled($epc->serial_number)) {
            return '(01)'.(string) $epc->gtin14.'(21)'.(string) $epc->serial_number;
        }

        if (filled($dual['primary']) && $dual['primary'] !== '—' && ! str_starts_with($dual['primary'], 'urn:')) {
            return $dual['primary'];
        }

        // URN only when no human-readable identifier is available.
        if (filled($epc->epc_uri)) {
            return (string) $epc->epc_uri;
        }

        return null;
    }

    /**
     * @param  array{sscc18?: ?string, element_string?: ?string, ai_00?: ?string}  $label
     */
    public static function scanForSsccLabel(array $label): ?string
    {
        if (filled($label['element_string'] ?? null)) {
            return (string) $label['element_string'];
        }

        if (filled($label['ai_00'] ?? null)) {
            return (string) $label['ai_00'];
        }

        if (filled($label['sscc18'] ?? null)) {
            return '(00)'.(string) $label['sscc18'];
        }

        return null;
    }

    public static function scanForGtinSerial(?string $gtin14, ?string $serial): ?string
    {
        if (! filled($gtin14) || ! filled($serial)) {
            return null;
        }

        return '(01)'.(string) $gtin14.'(21)'.(string) $serial;
    }

    public static function url(?string $scan): ?string
    {
        if (! filled($scan)) {
            return null;
        }

        return AssetTracking::getUrl(['scan' => (string) $scan], panel: 'app');
    }

    public static function forEpc(?Epc $epc): ?string
    {
        return self::url(self::scanForEpc($epc));
    }

    /**
     * @param  callable(mixed): (?Epc)  $resolveEpc
     */
    public static function linkEpcColumn(TextColumn $column, callable $resolveEpc, bool $copyable = false): TextColumn
    {
        $column = $column
            ->url(function (mixed $record) use ($resolveEpc): ?string {
                if ($record === null) {
                    return null;
                }

                return self::forEpc($resolveEpc($record));
            })
            ->extraCellAttributes([
                'class' => CopyableIdentifier::CELL_CLASS,
            ], merge: true);

        return $copyable ? CopyableIdentifier::applyToColumn($column) : $column;
    }

    /**
     * @param  callable(mixed): (?string)  $resolveScan
     */
    public static function linkScanColumn(TextColumn $column, callable $resolveScan, bool $copyable = false): TextColumn
    {
        $column = $column
            ->url(function (mixed $record) use ($resolveScan): ?string {
                if ($record === null) {
                    return null;
                }

                return self::url($resolveScan($record));
            })
            ->extraCellAttributes([
                'class' => CopyableIdentifier::CELL_CLASS,
            ], merge: true);

        return $copyable ? CopyableIdentifier::applyToColumn($column) : $column;
    }

    /**
     * @param  callable(mixed): (?Epc)  $resolveEpc
     */
    public static function linkEpcEntry(TextEntry $entry, callable $resolveEpc, bool $copyable = false): TextEntry
    {
        $entry = $entry
            ->url(function (mixed $record) use ($resolveEpc): ?string {
                if ($record === null) {
                    return null;
                }

                return self::forEpc($resolveEpc($record));
            })
            ->extraAttributes([
                'class' => CopyableIdentifier::CELL_CLASS,
            ], merge: true);

        return $copyable ? CopyableIdentifier::applyToEntry($entry) : $entry;
    }
}
