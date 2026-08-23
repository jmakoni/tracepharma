<?php

namespace App\Support\Receiving;

/**
 * Tenant receive SOP: sealed vs open-count. Stored as receiving.edge_mode.
 * When unset, ReceivingPolicy infers from the profile — open_tote is never implicit.
 */
enum ReceivingEdgeMode: string
{
    case SealedParent = 'sealed_parent';
    case ToteLpn = 'tote_lpn';
    case OpenCount = 'open_count';
    case OpenTote = 'open_tote';

    public function label(): string
    {
        return match ($this) {
            self::SealedParent => 'Sealed parent (pallet)',
            self::ToteLpn => 'Sealed tote / LPN',
            self::OpenCount => 'Open count',
            self::OpenTote => 'Open tote',
        };
    }

    public function chipLabel(): string
    {
        return match ($this) {
            self::SealedParent => 'Sealed parent — Edge-style',
            self::ToteLpn => 'Sealed tote — Edge-style',
            self::OpenCount => 'Open count — Edge-style',
            self::OpenTote => 'Open tote — Edge-style',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $mode) {
            $options[$mode->value] = $mode->label();
        }

        return $options;
    }
}
