<?php

namespace App\Support\Receiving;

/**
 * Tenant receive SOP: sealed vs open-count. Stored as receiving.edge_mode.
 * When unset, ReceivingPolicy infers from the profile — open_tote / case_only /
 * units_only are never implicit.
 */
enum ReceivingEdgeMode: string
{
    case SealedParent = 'sealed_parent';
    case ToteLpn = 'tote_lpn';
    case CaseOnly = 'case_only';
    case OpenCount = 'open_count';
    case OpenTote = 'open_tote';
    case UnitsOnly = 'units_only';

    public function label(): string
    {
        return match ($this) {
            self::SealedParent => 'Sealed parent (pallet)',
            self::ToteLpn => 'Sealed tote / LPN',
            self::CaseOnly => 'Case only',
            self::OpenCount => 'Open count',
            self::OpenTote => 'Open tote',
            self::UnitsOnly => 'Units only',
        };
    }

    public function chipLabel(): string
    {
        return match ($this) {
            self::SealedParent => 'Sealed parent — receive policy',
            self::ToteLpn => 'Sealed tote — receive policy',
            self::CaseOnly => 'Case only — receive policy',
            self::OpenCount => 'Open count — receive policy',
            self::OpenTote => 'Open tote — receive policy',
            self::UnitsOnly => 'Units only — receive policy',
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
