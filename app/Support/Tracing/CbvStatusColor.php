<?php

namespace App\Support\Tracing;

/**
 * Map GS1 CBV disposition / business-step / action values to Filament badge colors.
 */
final class CbvStatusColor
{
    public static function disposition(?string $uriOrLocal): string
    {
        return match (self::localName($uriOrLocal)) {
            'active', 'retail_sold' => 'success',
            'in_progress' => 'info',
            'in_transit' => 'primary',
            'encoded', 'reserved' => 'gray',
            'returned' => 'warning',
            'expired', 'recalled', 'destroyed', 'decommissioned' => 'danger',
            default => 'gray',
        };
    }

    public static function businessStep(?string $uriOrLocal): string
    {
        $local = self::localName($uriOrLocal);

        return match (true) {
            $local === '' => 'gray',
            $local === 'commissioning' => 'info',
            in_array($local, ['packing', 'unpacking', 'picking', 'loading', 'unloading'], true) => 'gray',
            in_array($local, ['shipping', 'departing'], true) => 'primary',
            in_array($local, ['receiving', 'arriving', 'accepting'], true) => 'success',
            in_array($local, ['storing', 'stocking'], true) => 'info',
            in_array($local, ['holding', 'inspecting', 'void_shipping'], true) => 'warning',
            $local === 'decommissioning' => 'danger',
            default => 'gray',
        };
    }

    public static function action(?string $action): string
    {
        return match (strtoupper(trim((string) $action))) {
            'DELETE' => 'warning',
            'ADD', 'OBSERVE' => 'gray',
            default => 'gray',
        };
    }

    /**
     * DaisyUI badge modifier for Blade (maps Filament semantic colors).
     */
    public static function daisyBadgeClass(string $filamentColor): string
    {
        return match ($filamentColor) {
            'success' => 'badge-success',
            'warning' => 'badge-warning',
            'danger' => 'badge-error',
            'info' => 'badge-info',
            'primary' => 'badge-primary',
            default => 'badge-ghost',
        };
    }

    private static function localName(?string $uriOrLocal): string
    {
        if (! filled($uriOrLocal)) {
            return '';
        }

        $value = strtolower(trim($uriOrLocal));

        if (str_contains($value, ':')) {
            $value = (string) str($value)->afterLast(':');
        }

        return $value;
    }
}
